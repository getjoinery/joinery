# Drive Sync Client

The desktop client that keeps a folder on a computer matching a member's Drive.
It lives at `{repo root}/sync/`, is written in Rust, and ships as one background
process plus an optional tray icon on macOS, Windows, and Linux.

The server side it talks to is documented in [Drive](drive.md#sync-clients);
this file is the client.

---

## What it is made of

```
{repo root}/sync/
  jd-proto/      the server API: auth, actions, chunked upload, ranged download
  jd-crypto/     the client-custody crypto, byte-matching the browser's
  jd-vfs/        the filesystem the engine is allowed to see
  jd-platform/   the operating system, everywhere it is not the filesystem
  jd-core/       the engine: state store, reconciler, ordering, executor
  jd-sim/        a simulated world: virtual disks, a mock server, injected faults
  jd-daemon/     the program: linking, the loop, health, the control channel
  jd-shell/      the tray icon
```

Two boundaries carry most of the design.

**The engine takes its filesystem and its network by injection.** `jd-core`
never calls `std::fs` and never opens a socket; it goes through the `Vfs` and
`DriveApi` traits. That is what lets `jd-sim` run the shipping engine against a
disk that lies about modification times and a network that loses answers.

**Everything per-operating-system is data, not `#[cfg]`.** Whether a volume can
tell `Report.txt` from `report.txt`, whether it hands names back decomposed,
which characters it refuses — all of it is a [`Personality`](#filesystem-personalities)
value. So the Windows rules can be exercised on Linux, and a Windows-only bug is
findable on a Linux dev box.

---

## The state store

One SQLite database per sync root, in the state directory and never inside the
synced tree. It holds:

| Table | What it is for |
|---|---|
| `meta` | Schema version, instance URL, device id, and the **cursor** — this device's position in the change feed. |
| `entries` | One row per known entity, keyed by `(entity_type, server_id)`. |
| `ops` | The intent journal: every operation is written here, with its idempotency key, before it runs. |
| `local_index` | A hash cache keyed by `(file_id, size, mtime_ns)`, so a rescan does not re-read unchanged files. |
| `issues` | The things a person is told about. |

### Last-agreed state

An `entries` row carries three pictures of one entity: what the server has now,
what is on this disk now, and — the field everything turns on — **what the two
sides last agreed on**. Sync is not "copy the newer one"; it is working out what
each side did since they agreed, from those three.

A store whose `schema_version` is newer than the running build is refused rather
than opened. Half-understanding it would produce confident wrong answers about
what was agreed, and those answers overwrite files.

### Identity is the server id

An entry is `(entity_type, server_id)`; paths are labels. Renaming a folder of
ten thousand files is therefore one operation, and a moved file keeps its
sharing and its version history.

Something created locally needs an identity before the server has given it one,
so it gets a **negative** id, allocated locally and counting down. Negative
rather than a flag, because a sign cannot get out of step with the thing it
describes. When the create lands, the row is re-keyed to the real id — together
with its children's parent pointers, its `local_index` rows, and its journal
rows, in one transaction.

### A file's own identity

The server id says which entity a record is. A file's own identity says which
file on this disk is that record's: the pair of its file id (the inode, or the
volume file index on Windows) and its birth time. A rename or a move within the
volume keeps both; a safe-save, a copy or a new file gets a new pair, and a
recycled file number arrives with a new birth. Each file record keeps its own
file (`own_file`): set when a scan mints the record, when a download or upload
lands, and when the record takes a new file (a safe-save read as an edit);
carried by every engine move; handed over, never copied, when the engine gives
the file to another record.

- **Strong and weak volumes.** A pair with both halves read, on a volume whose
  ids survive a rename (`Personality::stable_file_identity`, which the
  personality probe decides by renaming a probe file), is strong. FAT and
  exFAT (positional ids), a volume that reports no birth or a birth that is
  not the moment the file was made, one the probe catches changing an id or
  that cannot number files uniquely (ReFS), and one the probe cannot write
  to are weak. Every FAT or exFAT drive carries a standing notice saying that
  renames there cannot be tracked: files with identical contents that swap
  names may get their histories mixed up, each such pair named when it
  happens, and a renamed folder that holds fewer than two files, or whose
  files were being moved or changed at the same time, may be read as deleted,
  its copy on the server going to the trash. On a Mac, whose FAT and exFAT
  drives keep each folder's identity (below), the notice names the files
  alone.
- **The scan pairs by identity first** on a strong volume: a record's own file
  is that record's wherever it stands, and the path decides only for a file no
  record owns. A weak volume pairs path-first. Where a record's file stands
  under several names, by its identity or by the bytes a weak volume follows
  it by, one in its own folder is its file, then one that kept its name.
  Where two records want one path, the one at home there — its own file, or
  with no id its agreed bytes — owns the file at it: the other is not read as
  having saved over it, and a file of the other's set aside beside it is that
  record's, not its backup.
- **A save by rename is an edit whichever pass sees it,** on a strong volume.
  A file never sent, renamed over a record whose own file stands nowhere, is
  that record's next version, as a file no record owns at that path is. The
  record minted for it when a pass saw it under its temporary name is
  dropped, with anything queued for it. It is read so only where the file
  was seen under another name in the same folder: every record never sent
  keeps where its own file last stood on this disk, written by the scan and
  by the engine's own moves of the file, never by naming. A file carried in
  from another folder, one whose record wears a name naming planned but the
  disk never gave it, and one whose last place is not known are each a file
  in their own right: a delete plus a creation. So is a save whose temporary
  file is kept in another folder. A weak volume reads a save by rename, as
  any file moved and edited between two scans, as a delete plus a creation.
- **The executor acts only on the file it planned for.** On a strong volume a
  move, a park, a trash or a conflict copy of a file stands down
  (`Overtaken`) when the file at the path is not the record's own, and the
  next scan decides from where that file is. A park whose record's own file
  stands elsewhere on the disk stands down too, record untouched, rather than
  give the record up: the user moved the file, and the move the next scan finds
  ends the clash naming judged. Not where the server has moved the file as
  well and what holds the server's placement here is staying -- a file held
  outside its vault, or one the server keeps at that name: a move race goes to
  the server, so the file is due there whatever the move here says, and the
  park acts on the file where it stands, as it does for a name the disk cannot
  hold. A holder on its way out, or a file never sent that will go up under a
  name of its own, leaves the name to the move a pass later. An upload or an
  adoption opens the file once (`Vfs::open_file`) and reads its identity, its
  hash and the bytes it sends from that one handle, so a file renamed over the
  path part way through cannot go up as this record's. On a weak volume every op acts
  on its path, and a swap between the scan and the op is corrected only by
  the next scan.

### A folder's own directory

A folder record keeps its directory's identity the same way: the directory's
id and birth, recorded when the record is minted, when it moves, and from the
directory standing at its agreed path on each scan -- and from the one new
directory an empty vault folder is read as renamed into, when it is gone from
its place with exactly one new empty directory beside it, so the move that
follows takes that directory as the vault's own.

- **Proven: id and birth both match**, on a strong volume. The folder follows
  its directory wherever it went, whatever is inside it, even when every file
  the folder had was moved out first, and onto a path the server is giving
  another folder in the same pass: the race over that name is naming's. A directory made where another was
  deleted can inherit its id (`rm -rf A; mkdir B` hands `B` `A`'s inode on
  ext4) but never its birth, so a proven directory is never a stranger.
  The same pair says when the folder's directory is gone: its id standing on
  a directory with another birth is a later directory that took it, so the
  record forgets the id before the scan reads any directory, and learns the
  directory standing at its agreed path.
  A vault whose proven directory stands where the server is moving another
  folder waits for that move to claim it, and waiting is not leaving: the
  vault is present and its directory held, never taken up as a new plain
  folder that the vault's files would go up through in the clear.
- **Id only** (no birth reported, or a weak volume). The id corroborates what
  the folder's files propose and never claims on its own. A folder whose
  files point to one new directory while its id stands at another is held,
  present and unmoved: the issues panel names both places, and the hold lifts
  when the directory is back at the folder's own path or gone.
- **No ids at all** (FAT and exFAT, whose ids are positions). On a Mac a FAT or
  exFAT drive keeps each folder's own identity, its first cluster and its
  birth, which a rename, a move and a remount keep
  (`Personality::fat_directories_hold`), while its files' ids are positions as
  on any FAT drive. There a folder whose old name holds a new directory, one no
  folder record knows, while its own directory stands, by id and birth, at a
  directory no other folder holds, is that folder renamed: the user renamed it
  and then saved through its old name. Never a vault, never into or out of a
  vault's directory or a park, and only where the id and birth stand once (a
  freed cluster comes back after a remount, never with its birth). No other
  rule matches by those ids. Otherwise, and on Linux and Windows, a folder gone
  from its path is found by its files' bytes: the one new directory holding
  every file the folder has, at least two, each at its place inside the folder
  and with the bytes the engine last saw, is the folder renamed, so long as no
  other gone folder fits that directory and it holds no sealed file. A folder
  inside it goes with it under its own name, whatever it holds. Anything else
  (a folder of one file or none, one whose files were moved or changed in the
  same breath, two folders that fit one directory, a vault) is read as deleted,
  its copy on the server going to the trash.

---

## One pass

The client is this loop, run over and over:

1. **Read the change feed** from the stored cursor (`drive_changes`), or walk
   the whole index (`drive_index`) when the server answers `{reset: true}` and
   on a store's first pass. The feed keeps only what changed within its
   retention window, and a cold start is answered from the window's first row
   rather than with a reset, so a store this client starts walks the index: a
   file untouched for longer has no feed row left to read.
   Every request asks for `scope: mine`: the client syncs the account's own
   Drive, never what another member shares with it. After any full walk the
   client asks `drive_stat`, by id, about every live record the walk did not
   return; one the server answers as `missing` (gone, or not the account's)
   reads as deleted. Nothing is decided by its absence from the walk alone. A
   store not yet marked as walked under this scope (`OWN_DRIVE_ONLY`) is walked
   this way once, which takes down anything of another member's it holds, each
   copy going to this computer's trash as a deletion would, an edit that never
   reached the server rescued into the folder it was made in. A store the
   client starts, at no feed position and holding nothing, is marked without a
   walk.
2. **Absorb** what the server reports into the remote side of `entries`. An
   observation, never an agreement.
3. **Resolve names** for this filesystem ([below](#filesystem-personalities)).
4. **Walk the disk**, hashing what the fingerprint cache says may have changed.
5. **Pair** what is on disk against what the engine believes it is called.
6. **Decide**, per entry, through the reconciliation matrix.
7. **Order** the decided actions into a safe sequence.
8. **Journal** every operation, with its idempotency key, before any runs.
9. **Execute**, with bounded concurrency and per-entry serialization.
10. **Advance the cursor** — last, and only now.

Two orderings there are load-bearing. The cursor moves last, because the feed
mentions a change exactly once and a cursor advanced early is a change nobody
will look for again. And **deltas are measured from the last agreement, never
from the last observation**: an edit reported once and interrupted before it
landed is reported again next pass, and the pass after, until the bytes are
actually here.

### When a pass runs

In the order the daemon checks: something on disk changed and has gone quiet
(2 s); the watcher admitted it lost events; the poll interval elapsed (30 s);
somebody asked for one. Anything that changes the server schedules an immediate
next pass rather than waiting out the interval.

The last two conditions are what make the client work when the first two fail. A
watcher that dies degrades this into a thirty-second polling client, not into a
client that has stopped.

### Standing down

A stop, a pause or an unlink reaches the pass in progress the moment it is
posted, not when the pass ends. The engine asks whether to carry on
(`ExecEnv::stand_down`) before each operation and before each chunk of a
transfer: an upload reads its file just before it sends each chunk, and a
download asks as each piece of its bytes arrives. Asked to stand down, the pass
stops there:

- Operations it has not started stay queued, untouched: no attempt is counted
  and nothing is sent.
- An operation stopped part-way through a transfer goes straight back on the
  queue under its own idempotency key, as it was before it ran: its attempt
  count, retry time and last error are untouched, and it runs again from its
  start. A stand-down only ever comes before a transfer's completion call, so
  the server holds nothing of the stopped attempt but, for an upload, a
  half-sent session that is abandoned as a crash abandons it; the next run
  opens a new one. A download's partial bytes go with its spool. Nothing is
  counted against it, so pausing one large upload over and over never makes it
  read as work that keeps failing, and never lengthens its backoff.
- The pass still finishes its bookkeeping and advances the cursor: everything
  the batch implied is already in the journal. Its outcome says it stood down
  (`ExecReport::stood_down`), and a pass that stood down is never a quiet one.

The daemon reads its command queue the moment a pass stands down, so a stop or
a pause lands within one chunk of an upload, or one piece of a download. A
chunk already on the wire is let finish. These are not interrupted, and a stop
or a pause posted while one runs lands at the first operation after it, or at
the transfer's first chunk:

- reading the change feed, or walking the index;
- the scan, including hashing what changed on disk (the whole folder, on a
  first pass over one that already holds files);
- deciding and ordering what to do;
- the whole-file hash an upload takes before it starts;
- writing the encrypted copy of a file in an encrypted folder, before its
  upload starts.

A download whose connection has gone quiet asks only when bytes arrive, so its
stop waits for the next bytes or for the client's 60-second silence limit to
drop the connection.

The daemon's control thread says it (`Shared::send`), for the tray, the CLI,
the settings page, and the update thread installing an update alike; the
command still waits in the queue, and the loop acts on it once the pass is out.
A resume lifts a pause and never a stop. The simulator never asks a pass to
stand down: its devices are stopped by being killed, which is the harder case.

---

## Deciding what happens

Per entry, each side's change is one of: none, edited, created, deleted, or
moved. Content and location are separate axes, so a remote move and a local edit
compose into "apply the move, then upload" rather than fighting.

Three rules run underneath the whole table:

**An edit beats a delete, in both directions.** They are not symmetric outcomes:
a delete that loses is recoverable from a trash, an edit that loses is gone. So
the edit survives, even where that resurrects a file somebody meant to remove.
That holds inside a folder too. A folder deleted here that holds a file edited
elsewhere since this device last agreed on it comes back, at the server's name
and place, holding only the edited file; what nobody touched goes to the trash
on its own, and the user is told the folder came back for the edit. So it does
for a file this device agreed on that a peer edited, wherever this device's
round puts it: moved into the folder by the peer while this device left it where
it was, or edited here too, the peer's edit stands where the peer put it and an
edit made here stands beside it. A file whose move here keeps it out of the
folder goes where it was moved; where the server's move wins, or this device
only renamed it, the folder comes back for it. A trash decided before the edit
was heard of stands down when it runs. New content counts the same: a file
another device saved into a folder deleted here, which this device never had,
brings the folder back holding it, and the user is told the folder came back for
a file another device was still sending. This device's own files that were never
sent go with the folder its user deleted. The server trashes a folder with
everything in it, so an edit that reaches the server after this device last read
the change feed and before its trash lands goes to the server's trash with the
folder. A file or folder this device holds outside a folder it deletes is not
handed to that folder: where a peer's move would land it there, in a race with
this device's own move of it or on its own, it stays where it stands here and is
moved there on the server. Where the folder comes back for an edit, what the
peer moved into it stays there.

**Deletes only ever win against unchanged content.** A remote move with a local
delete resolves as a delete only when the last-agreed hash still matches the
remote head.

**Nothing is adopted on a fingerprint's word.** Whenever the engine concludes
"these two are the same, no transfer needed", that comes from comparing content
hashes. Size and modification time only decide whether it is worth hashing.

### Conflicts

The remote head keeps the path the user knows. The losing local content is
preserved beside it and uploaded as a new file:

```
Report (conflicted copy 2026-07-31 from MacBook).xlsx
```

Both versions exist on both sides within one sync round, and the conflict always
lands in the issues panel. The copy is named after what the file is called on
the server, unless that is the scratch name a peer's swap holds it under for a
moment (`.jd-swap-...`); then it is named after what the file is called here.

A file or folder moved on both sides is judged half by half against the
placement both sides last agreed: its folder and its name. A half only one side
changed takes that side's value, and a half both sides changed to the same
value takes it, so a rename on one side and a move on the other both survive,
and two devices making the same rename keep any move only one of them made. A
half both sides changed to different values is settled by the server's value,
and the issues panel says which half of the local move lost. Every device ends
at the same placement. With no agreement to measure against, the server's
placement settles the whole move.

### The mass-delete guard

If one round would delete more than `max(50, 25%)` of the settled entries — in
either direction — that class of operations is withheld and a person is asked.
Ransomware, a volume that mounted empty, and a legitimate cleanup are
byte-for-byte identical from here, and there is no clever test that separates
them.

What a round would delete is counted in records, not in operations: a folder's
delete counts every synced record under it on both sides as well, which is
what it removes. A folder deleted here reaches the round as a delete of each
record in it; a folder trashed on the server arrives as one change in the feed
and one local trash, and counts the same. A record under it on only one side —
moved out here, or by the server — is moved out before the trash runs, and is
not counted. The records it would remove are held with it, and named with it.

Only deletes are withheld, and only in the blocked direction; ordinary transfers
in the same round still run. An unavailable sync root is a separate case: it
hard-pauses rather than reading as a mass local delete. Each device's guard
answers for its own side, so a large delete approved on one device is asked
about again on every other device that would remove it.

The person is asked through one `deletes_held` issue for the device, so the
tray shows Attention for as long as anything is held. It says how many items
are held on each side (deleted here and not on the server, or deleted on the
server or another device and not here), names up to three of them by the path
both sides last agreed (a held folder stands for the held items inside it),
says how to keep them — put them back on this computer, or restore them from
the trash on the web — and names the held set: a fingerprint, sixteen hex
digits of a SHA-256 over the sorted list of side and entity. The same items
held the same way give the same fingerprint on every pass. The issue is a
state, re-derived each pass and withdrawn the pass nothing is held; when the
held set changes, its words and fingerprint change with it.

Going ahead is an approval of exactly the set the person was shown: the
settings page's **Approve these deletes** button, or
`joinery-drive approve-deletes`, which shows what is held and asks; given the
fingerprint (`joinery-drive approve-deletes <held-set>`) it approves without
asking, and only if that is still the set held. Either way the daemon is sent
a control request, `POST /approve-deletes` with `{"fingerprint": …}`, which it
refuses (409) when the fingerprint is not the one its last snapshot holds; the
status answer carries the open question on its own as `held_deletes` (the
issue's id, its words and `held_set`), outside the capped issue list. The
approval is written to the store's `meta` table (`deletes_approved`), so a
restart keeps it, and the next round reads it: if what that round would hold
has exactly that fingerprint, its deletes go into the journal like any others;
if anything was added, taken away or turned the other way since, nothing is
let through, and the issue asks again under the new fingerprint. The approval
is spent by the round that reads it, whether or not it matched.

A machine with no tray says so in the daemon's log, on stderr: one line when a
new held set appears (its fingerprint, how many to remove here and how many to
trash on the server), one when an approved set is carried out, and one when
nothing is held any more.

---

## A file's date

A file shows the date its content was last changed on every device it reaches,
not the moment it arrived there.

**An upload declares the date**, read off the same open handle its bytes are
sent from: the `modified_time` parameter for a plaintext file, and the `mtime`
field inside the encrypted metadata for a vault file, whose date the server is
never told ([below](#encrypted-files-and-folders)). Both are written as a
browser writes a date, `2026-07-20T12:34:56.789Z` (`jd-proto/src/mtime.rs`).
The server keeps a plaintext file's date to the second, so that is what the
other devices get; a vault file's keeps milliseconds. A date more than forty
years past this device's clock is not declared, because the server refuses one
fifty years past its own and the upload would never land; the content still
goes up.

**A download puts the date on the file before anyone can see it.** It goes on
the spool after the last byte and before the rename
(`SpoolFile::set_modified`), never on the path afterwards, where it would land
on whatever the user had saved there in the meantime. The fingerprint the
commit returns is read after the rename, so the agreement records the file with
its date on it. Recorded before the date went on, it would describe a file that
is no longer there, and the next scan would read every downloaded file as
edited. The volume keeps what it can (two-second steps on FAT, whole seconds on
HFS+) and the agreement records what it kept. A file put up with no date, as
the browser's uploader puts one, lands with the time it arrived. A volume that
will not take a date at all still takes the file.

**Dates follow content and nothing else.** An edit carries its new date. A
rename or a move sends no content, so it keeps the date the content had, on the
server and on every device. A date changed with the bytes left alone (`touch`)
sends nothing, because the hash, not the date, decides whether anything
changed. A fingerprint's time is nanoseconds since 1970 on every platform,
Windows included, so the date a fingerprint holds is the date every other
program on the computer reads.

---

## Filesystem personalities

The engine is written against one clean tree: case-sensitive, NFC, legal names
only. `jd-vfs` makes every real filesystem look like that, and reports what it
could not.

A `Personality` records what a filesystem will do: case sensitivity, whether it
decomposes Unicode, which characters and stems it refuses, whether it strips
trailing dots, its name and path budgets, and its modification-time granularity.

**Case sensitivity and Unicode normalization are probed from the volume, not
assumed from the operating system.** A developer's case-sensitive APFS volume, a
Windows directory with per-directory case sensitivity, an exFAT stick on Linux —
all are the same OS and a different answer. `Personality::probe()` writes one
file, asks for it back under a different spelling, and believes the result.

### Three possible names

Every entry is asked what it is called here, and there are exactly three
answers:

- **The same as on the server** — the overwhelming majority.
- **An adjusted name**, recorded in the entry's `local_name`. `Q3: final.xlsx`
  becomes `Q3%3A final.xlsx` on Windows; `CON.txt` and `report.` are escaped for
  the same reason. The recorded mapping is authoritative — the escape is not
  relied on to be reversible, so a genuine `%3A` in a filename is not a trap.
- **It cannot exist here** — recorded as `unsyncable` with a reason, and
  surfaced.

The third is the one that matters. Materializing the second of two
case-clashing siblings under a mangled name would look tidier and be worse: the
mangled name reads as a rename on the next scan and gets pushed to the server,
renaming the user's file on every device they own. Refusing leaves the file
exactly where it was, and says so.

This is not only about folded spellings. A file and a folder of the same name
are one slot on every disk, whatever it folds, and the same rule settles it:
whichever is already here claims it and the other parks.

A parked FOLDER takes what is inside it with it. Its children are held for
exactly as long as it is: a parked folder has no directory of its own but still
has a name, and a child left in the round would resolve onto whatever directory
won the slot and land inside it -- one folder holding two folders' contents,
with nothing on the disk saying so. The folder's own issue is the only thing
the user is asked to act on, and the clash clearing releases the whole subtree
at once. A parked folder that has a directory of its own is not held this way:
what is inside it is genuinely there.

A folder given up here while its directory stands takes that directory to the
OS trash, once nothing inside is owed elsewhere. Work the server does not have
is carried out beside it first, and the give-up waits while something inside
still has somewhere to go: a child the server has moved out, a file of another
record's the user moved in, a file saved there and not yet sent. A sealed one
waiting for a key holds it until there is one, and says so. A file carried out
of its vault and held there -- sent before or not -- has nowhere on the server
to go, so it does not hold the give-up: it is carried out beside the folder with
the rest, its record following it, still held.

Which sibling wins: anything already on this disk claims first, then the lowest
server id. A file held outside its vault claims before any of them: it stands
where it is held, and a record that agrees on the same name cannot also have its
file there. The consequence, stated honestly — two devices that downloaded a
clashing pair in different orders keep different members of it. Both remain on
the server and both devices report the clash. An entry recovers by itself, with
no user action, as soon as the clash clears.

Three reasons, and they are told apart because the fixes differ:

| Reason | What happened | What the user does |
|---|---|---|
| `case_clash` | Two names differ only by capitalization, on a disk that cannot tell them apart | Rename one |
| `unicode_clash` | Two names are different bytes that normalize to one form — `café` typed two ways | Pick one spelling |
| `duplicate_name` | The server is holding two live items in one folder under the *same* name, and neither is an encrypted file | Nothing they can do from here — the second item has no path to be seen at, so the message points them at the web |

`duplicate_name` should be rare outside a vault: the server refuses a name a
live sibling already holds. It is reported rather than folded into
`unicode_clash` because a name like `app.db-wal` has no spelling to argue about,
and reading it as a Unicode problem sends whoever investigates to the wrong place
entirely.

**Inside a vault it is ordinary, and the server cannot help.** Uniqueness is
enforced on the stored title, and an encrypted file's title is an opaque
per-file identifier that is unique by construction — so two files in one vault
folder whose real names are both `notes.txt` are a state the server cannot see,
let alone refuse. The client is the only thing that can tell, and it resolves it
by renaming: the lowest server id keeps the name, and every other holder becomes
`notes (2).txt`, `notes (3).txt` and so on, applied by resealing that file's
metadata.

The rule is the server id rather than anything a device can see for itself.
Resolution order ranks materialized entries first, so a file already on disk
here and not on the other computer would win here and lose there — each device
renaming the other's file, neither ever holding still. An id is the same number
everywhere, so every device arrives at the same answer without having to agree
first. An entry the server has never seen loses to one it has: it has no id to
compare and nobody has been told about it, so renaming it disturbs nothing.

A parked entry keeps recording the placement it is waiting for, and that record
is **not** ownership of the path: it holds no bytes there and never did. Whatever
asks "does this path belong to another entry" has to skip parked entries
(`Entry::holds_a_local_file`), or the file that won the name can never upload
again — its transfer is skipped over every candidate path as somebody else's,
found nowhere, dropped as overtaken, and planned again on the next pass. Nothing
fails, nothing queues, no issue is raised, and the device never goes quiet.

When the question is answered honestly and the path really does belong to
another entry, the transfer still finds nothing — but for a different reason,
and the difference decides what may be thrown away. A file found nowhere is
gone, and an identity minted only because the scan saw an untracked file has
nothing left to be about, so it is dropped. A file whose every candidate was
refused on the **name** is still on the disk, and dropping its identity would
say otherwise: the next scan finds the same untracked file, mints another
identity, and arrives back at the same refusal. So a refusal on the name leaves
the entry alone. Deciding who gets a contested name belongs to naming, and an
identity minted after naming has already run for its pass can only be judged by
surviving into the next one — where the loser is parked, visibly, under
`unsyncable`. Nothing is raised from the transfer itself: the park carries the
issue, and `unsyncable` is one of the kinds a pass withdraws again once the
state ends.

Moves are ordered by what they wait on. A rename into a name another mover
still holds waits for that mover; a folder moved into what is currently its own
subtree (a parent swapped under its child) waits for the move that takes that
folder out, and with nothing on that chain moving it is left out of the round
until the chain changes. The local move refuses the same shape itself rather
than asking the disk for a directory to move into itself. A folder being
created on this disk takes a slot the same way a move does: it waits for the
mover holding that slot, and a move into it waits for it to exist. When those
two wait on each other (the server replaced a folder with a namesake and moved
the old one inside it) the mover is parked under a scratch name in place, the
folder is created, and the mover moves in. A local park is recorded as the
spelling on disk, never as the agreement, and naming leaves a parked entry
alone. The walk does not read inside a scratch directory, so while a park
stands -- its finish refused, or a pass stopped between the two -- nothing
under it is judged: it is all held, present and unmoved, until the finish
lands. What stands in it is known by identity alone: a file the user carries
out of the park is followed to where it went, and a file carried in from
outside is held with the park until it ends. A park this device made that nothing is left to finish is finished:
moved from where it stands to where the server has it, since the name it left
belongs by then to the other half of the swap. That finish is judged as any
arrival is: a name this disk cannot hold, or one a sibling here already holds,
gives up the copy here as it would for any folder renamed to such a name, and a
finish overtaken for any other reason is said until the park ends. A move into
a name whose holder is still being parked out of it waits for the park, and a
park's finish waits for its own park to land.

A rename the server refuses because the name is taken lands beside what holds
it, under a conflict name, and the disk follows; what holds a name this device
is renaming away is waited for instead. A server that refuses without saying
why (one that does not say its refusals are reasoned, below) is read the same
way for a folder whose name this device knows another live folder holds there,
one it is not itself renaming away: that folder's arrival waits on this one's
directory leaving the name, so neither would ever move. Any other folder rename
refused without a reason is withdrawn, and the next pass derives it again from
the disk until the name frees up.

A new file whose upload is refused because its name is taken goes up beside the
holder under a conflict name. Refused without a reason by a server that does
not say its refusals are reasoned, it does so only where the server lists
something under that name in that folder; otherwise the upload is withdrawn and
planned again from the disk, never sent as a conflicted copy of itself beside
nothing. The file on this disk takes the conflict name where it still stands at
the name refused; one the user moved or renamed after the upload was planned is
sent from where it stands and left there, and its move goes up on the next
pass. An upload whose bytes did not all arrive (`upload_incomplete`) is sent
again whole.

Every refusal is read by its reason ([Drive](drive.md#refusal-reasons)). A
server that answers its feed and its index with `refusals: reasoned` gives
every refusal one, and is taken at its word: `not_found`, `parent_not_found`
and `move_into_self` plan the work again from what is there, a trash answered
`not_found` is done, the server's own state (`no_room`, `storage_failed`,
`drive_disabled`, `signed_out`, `action_failed`) is tried again on the
backoff, `vault_needed` is held like a standing refusal (only an unlock
window lifts it, a window belongs to a browser session, and this computer
never holds one), and anything else keeps its status's reading, held where it
stands. Each action refused is held on its own, so a file with two things
refused (a Private file edited here and carried out: its move and its new
contents) asks about neither again until something it was asked about moves.
A refusal with no reason from such a server is withdrawn and planned
again, never read as a name clash, and its issue says it came without a
reason. The store keeps what the latest answer said, so a server that stops
saying so is read the older way again.

A create claims a directory already standing at its path — a stand-in it was
handed, or its own earlier attempt — and waits while that directory is one
another live folder still holds, so the folder replacing a namesake never
takes over the directory of the folder it replaces. A file in the way is moved
aside instead, because nothing else is coming for it. The park, the create and
the move that finishes them are three operations that fail one at a time, and
the finisher counts its park from the moment it is queued rather than from the
moment it lands: a park whose rename is refused keeps the finisher that is
going to put it back.

A scratch name (`.jd-swap-…`) is a different kind of park: one step inside one
operation, never a placement anyone agreed on. The planner mints one to break a
rename cycle and a remote move mints one as its last resort when both orders of
rename-and-reparent are refused; the operation that minted it knows its own park
by its key and finishes from there, and the park is recorded as `remote` alone.
A move is two calls, rename and reparent, and an op retried between them
recognises its own half-done work the same way: the new name in the old folder,
or the old name in the new one, is its own and it finishes from there rather
than standing down as somebody else's move. A first attempt claims nothing, and
a move the server has already completed has nothing left to finish; both fall
to the ordinary path. While an operation is open on an entry, or the entry
wears a scratch name on the server and the pass's put-back owns it, naming
leaves that entry's collisions alone: its agreement still names the slot it is
leaving, and a duplicate or clash read against that slot would release the copy
the operation is about to place. A verdict about the entry's own name still
stands.
An entry this device has never agreed on that wears another device's scratch
name is not landed here at all, not even by a download planned before the park
arrived: under the scratch name the walk cannot see it, and the next pass would
read it as deleted here. It waits for the park to end and arrives under its
real name.
A park nobody is coming back for — its operation withdrawn, or dropped after a
kill — is put back by the next pass under the name both sides last agreed, or
the next free one there, and said as a `reconcile` issue. It goes back into
the agreed folder while that folder is still a live folder in this store, and
beside where the server has it now when the agreed folder is gone. The put-back
is a rename on the server of something the disk has not followed, so it too is
recorded as `remote` alone, and the ordinary local move brings the directory
along on the pass after.

One shape of it is the client's own bookkeeping rather than anything the user
can act on, and is repaired without being shown. A device that creates a file or
folder gives it a provisional identity before the server has named it; if the
name is taken in the meantime the create is refused, and the real item then
arrives from the change feed as a second record of the same path. Left alone the
two are rivals — the provisional holds the name, the real one is refused against
a name identical to its own, and neither can ever supersede the other. The pass
folds a provisional into the real item at the same placement before naming runs,
so the pair resolves itself. What survives is the real identity, with whatever
was last agreed about it left intact, so an ordinary edit stays an edit instead
of becoming a conflicted copy.

### Encrypted files and folders

This section is about **Fortress** folders — the client-custody level, where the
device holds the keys. **Private** folders are not synced to computers: their
bytes are opened by the server inside the owner's unlock window, the window
belongs to a browser session, and a sync client never holds one. Both exports
carry `protection_level` and a `syncable` flag (an export without the flag reads
as syncable). The client parks a Private tree's top folder out of scope, as it
parks a vault on a FAT drive, and everything under it waits with it: nothing is
downloaded or created there, nothing saved there is uploaded, and nothing
already there is moved or removed. A folder that synced before it was made
Private keeps its copy on the computer, no longer synced, and one issue
(`private_not_synced`) says so; the Drive UI says so on the folder. A file
carried out of the folder on the computer is sent as a move, which the server
refuses until the owner's window is open (`vault_needed`, held like a standing
refusal), and a file never sent goes up from where it now stands. Made
Standard again, the folder syncs again, and what was done to it on the computer
meanwhile goes up.

Fortress content syncs like anything else, with one difference that runs
through everything: the engine works in the **plaintext** domain and the server
only ever sees the **ciphertext** one. For an encrypted file the server holds a
placeholder name (`enc-<content id>`), the hash and size of the ciphertext, and
no modification time at all — a plaintext timestamp would tell it when somebody
last worked on the file. The real name, mime, size and mtime live inside the
encrypted metadata blob, under the file's own key.

**Downloading.** The file's key is unsealed from its grant, then the ciphertext
is streamed through a decryptor into the spool. Every chunk is authenticated
against `contentId:index` *before* any of its plaintext is written, so a chunk
that was tampered with, reordered, or transplanted from another file stops the
transfer instead of reaching the disk. The transfer is still checked against the
server's own size and hash, in the ciphertext domain, because that is the only
thing the server ever measured. Nothing that failed to verify is committed;
failures raise a `ciphertext` issue rather than retrying silently.

**Uploading.** Encryption is a property of the **destination**, not of the file:
the server decides an upload is encrypted by looking at the folder it lands in,
so the client works it out the same way, when a new local file first gets an
identity. The plaintext is encrypted into a scratch file and sent from there —
a resumed chunk upload has to re-send bytes identical to the ones it already
sent, and re-encrypting cannot produce them, because every IV is fresh. What
goes to the server is the placeholder name, the ciphertext size, the hash of the
**ciphertext**, the metadata blob, and the file key sealed to every reader of the
destination folder (resolved through `drive_public_keys`). The plaintext hash is
never sent: it would tell the server what it is holding, and it is what dedup
matches on, so sending it would hand somebody else's file back as this one. The
ciphertext's gives away nothing the server does not already have, collides with
nothing (every encryption uses fresh IVs), and is what the assembled upload is
checked against — without it an encrypted upload has no integrity check at all,
and a chunk corrupted on the way up becomes a file no device can ever open. The owner's key is
mandatory: a vault file its owner can never open would be a file they can see,
are billed for, and have permanently lost.

The metadata carries the file's type and date as well as its name and size.
The type is the one a browser gives a file of that name (`jd-core/src/mime.rs`:
the extension, through the mappings browsers carry, or
`application/octet-stream`), because the server cannot read a type from
ciphertext and the browser shows a vault file by the type its metadata names: a
photo saved into a vault on a computer shows in the browser as a photo, not as
an unknown file. The date is the content's ([A file's date](#a-files-date)); a new version seals its
own, and a rename seals the date it found.

**Editing** an encrypted file uploads a new version under the *same* file key
and content id, with no key payload at all. A fresh key would leave the new
content readable only by the device that sent it, behind grants that every other
device holds and that all wrap the old key. The server refuses one.

**Change detection runs in two domains at once**, and this is the part that has
no shortcut. Local edits are found by comparing the disk against the plaintext
hash from the last sync; remote edits are found by comparing the server's answer
against the *ciphertext* hash from the last sync. The client never re-encrypts
to compare — encrypting the same bytes twice produces different bytes, so equal
plaintexts do not imply equal ciphertexts and never will. An engine that mixed
the two domains would report an edit on every pass, forever, for a file nobody
had touched.

One visible consequence: when both sides have edited an encrypted file, the
engine cannot tell "both arrived at identical bytes" from a genuine conflict, so
it keeps a conflict copy. That costs a file nobody needed; the alternative does
not exist.

**Without a key**, an entry waits rather than failing. Encrypted files and
folders are marked `pending_key` when this device has no vault key, when no
grant has arrived for that file, or when the grant is held but its metadata
could not be read — the last case matters because the entry is then still
holding the server's placeholder name, and materializing *that* is the failure
everything here is arranged to avoid. An encrypted folder is held back on a
device with no vault key for a sharper reason: it would look like an ordinary
directory, so the next file dropped into it would go up in the clear, into a
folder the user was told was private.

`pending_key` raises no per-entry alert. A laptop linked without encrypted
folders can be looking at a thousand of them, and a thousand identical alerts
would bury everything that does need a person. It is counted instead: the tray
and `joinery-drive status` say how many items are waiting for a key, and
`status` states plainly whether encrypted folders can be opened on this device.
An entry leaves `pending_key` by itself the moment the key arrives. A file the
device heard about while it had no key holds only the grant and the server's
placeholder name, because the feed mentions each file once; when the key
arrives, the files whose grant it opens are asked about again in one batched
stat and opened from the answer.

**A grant that arrives later** — a file shared with the account is visible
before its key is issued — comes with a `grant_changed` row on the feed,
addressed to the user who gained it, and that row is what names the file again:
it is asked about like any other change, and opened and downloaded from the
answer, with no walk of the index. A grant that is **withdrawn** is honoured by
the download itself, which goes by the grant in its own fresh `drive_stat`
answer rather than the one the record last heard of (a grant once seen still
opens the file, and the record never erases one on a stat's silence). With no
grant in that answer the file is not fetched and nothing of it is written, not
even to the spool; the record forgets the grant, so the file waits for a key,
and a `withdrawn` issue says the key was withdrawn. A key given again brings the
file down and withdraws the issue.

Of two grants for one file, the record keeps the one this device's key opens.
Both wrap the same file key — a file's key never changes, and a rotation
re-seals the wrapping, not the key — so a grant that arrives sealed to a key
this device does not hold never replaces one that opens, and a device holding a
retired vault key goes on following renames, reading new versions and sending
edits of every file it could already open. And whenever an encrypted file's
metadata does not open — the vault locked here, or the grant sealed to another
key — the record forgets its content id along with its real name: it waits for
a key, and its name is read again the first pass a key opens its grant. An old
content id kept would tell the engine it still knows the file, nothing would read
the metadata again, and a file renamed or moved elsewhere meanwhile would be
renamed on this disk to the server's placeholder.

**A rotated vault key is noticed.** Rotating the Drive vault key in the browser
(`drive_encryption.md`, Device custody) seals everything saved afterwards to a
key no linked computer holds, re-seals every existing grant to it, and writes
nothing to the feed. Files saved since then arrive here with grants this
device's key does not open, and wait. While any do — and only then — the
engine asks `vault_client_probe`, whose `public_key` is the account's current key;
when it is not the one this device's secret derives, one `vault_relink` state
issue says the encrypted folders need this computer linked again (`joinery-drive
login`, from the desktop session, where the keychain is open). An answer is not
asked for again until this device's key changes, more files are waiting than
when it was asked, or an hour has passed; a server that cannot be reached is
asked next pass. While the issue stands, `status` says the key was replaced
rather than that encrypted folders open here (`vault: false` and
`vault_relink: true` on the control channel). Everything it could open before
keeps syncing: a rotation closes only what is sealed afterwards, and does not shut
a computer out — removing the device does (`drive_encryption.md`, Device
custody).

Linked again, the device holds the new key. The issue is withdrawn on the next
pass the probe agrees, the waiting files open and come down, and the grants the
store holds are checked once against the new key: each that it does not open —
sealed to the retired key — is asked about again and replaced from the answer,
so editing or renaming a file synced before the rotation works as before.
Checking every grant costs a scalar multiplication per file, so it runs once per
key, not every pass.

A device with no key never materializes a vault folder, but nothing stops the
user making a directory of the vault's name and saving into it; what they save
there is held for a key. That directory is the vault's by identity, not by
name: the first time it is found at the folder's path it is recorded as the
folder's **stand-in** (`stand_in` on the entry, kept apart from the agreed
placement), and from then on it follows the server — renamed here when the
vault is renamed or moved there, with the held files resolving their paths
through it — and becomes the folder's directory when the key arrives. The
tie is deliberately not an agreement: a stand-in the user removes never held a
byte of the vault, so its going deletes nothing anywhere and the tie simply
lapses, where a materialized folder's directory going is the user deleting it.
Something else standing where the server has the vault is moved aside under a
conflict name first, unless it is a folder this device tracks, whose own record
says where it goes. A vault trashed on the server while files are held under
its stand-in is parked out of scope rather than forgotten: the directory is not
adopted, the files stay held and unsent, and the user is told; restored from
the trash, it goes back to waiting for a key. An empty placeholder of a trashed
vault is trashed with it, as a materialized empty vault folder is. On a disk
that folds case, a stand-in the user respells is the same directory: the walk
matches it by the disk's comparison key and the record takes the new spelling.

**Renaming** an encrypted file is a metadata re-encrypt, not a rename. The
current blob is fetched, decrypted, its name field changed, and the result
encrypted again under the same file key — the server stores it without learning
either name, and refuses a plaintext one outright. The blob is fetched rather
than rebuilt from what this device remembers, because another device may have
changed the mime type, the size or the thumbnail flag since, and rebuilding
would discard whatever this one did not know about. Moving a file *within* a
vault is an ordinary `drive_move` on ids: nothing re-encrypts. A vault folder's
own name is plaintext on the server and renames normally.

**Crossing the boundary is not a move.** The server holds no key, so it cannot
turn plaintext into ciphertext or back again, and it refuses an in-place
crossing with `reason: protection_boundary`. Nothing on the platform turns an
encrypted file back into plaintext — not the server, not the browser. The client
crosses only into a vault, by uploading the file again. A copy of a vault file
made outside the vault is a new file and goes up with the protection of the
folder it stands in; so does an editor's backup or save written outside the
vault.

**Into a vault, a file or folder is converted.** It goes up again inside the
vault as a new sealed file or folder, and the plaintext source is trashed once
that upload lands, wherever another device has moved it meanwhile. From the move
on, the new record owns the bytes where they stand and holds the source's server
copy until then — with no key on this device it waits for one, and the copy the
rest of the fleet can reach stays where it was — so a file saved at the old path
meanwhile is a new file, never the source's next version. A plaintext folder is
converted contents and all, each file inside crossing as its own conversion —
until it is, the user is looking at a folder they believe is private while the
server holds every file in it in the clear at the old path. An edit made
elsewhere to the source before its trash lands wins over that delete, as any
edit does: the source comes back here as the plain file it is, beside its sealed
copy, and where a file held outside its vault now stands at its name, it waits
for that name like any file from the server. A plaintext file found standing in
a vault folder where a download is arriving, and set aside there under a
conflict name, is converted the same way: its record stays where it was agreed,
and the next scan reads the file moved into the vault. On a disk whose file ids
cannot say which file is which, the record waiting to go up sealed knows its
file by the bytes it was last seen with: when another file comes to stand at its
name and those bytes stand at another, it follows them there, rather than being
read as gone and letting its source's plain copy go to the trash.

A file edited in the vault before its conversion has gone up belongs to the
vault from that edit on. Carried out again, it is held like any file saved in a
vault and moved out before it was uploaded, and the plain copy on the server
keeps the bytes it had. Carried out unedited, it is the plain file again.

**Out of a vault, a sealed file is held.** It stays where the user put it on
this disk, the server keeps the sealed copy where it was, and one issue names
the file and the two ways out: move it back into the vault, or download it in
the browser and upload it where it is wanted. The hold is read from the record
itself — a sealed file whose agreed folder here is plain while the server keeps
it in a vault — so it survives a restart and needs no bookkeeping beside it.
While it is held:

- Moving it again outside any vault — renaming it where it stands, or moving it
  to another plain folder — is this disk's side alone; the server is asked
  nothing. On a disk whose file ids cannot say which file is which, the held
  file is known by the bytes it was last seen with once another file stands at
  its path, and followed to them.
- Moving it back into a vault lands it on the server's placement, a move within
  the vault when it goes to a different folder there. Its own file standing in
  the slot the server keeps it in is the file come home even when another file
  now stands at the path it was held at: the hold releases, and a new file
  standing at the path it was held at goes up as a new file with that folder's
  protection.
- Deleting it trashes the sealed copy on the server.
- Edits wait on both sides: nothing edited here is sent, and a change made on
  another device is not written over the copy here. If the copy here is gone
  when the server's copy changes, the server's copy comes back into the vault.
- The server trashing the sealed copy removes an unedited copy here; an edited
  one is kept on this device only and never uploaded.
- A new file carrying the held file's disk identity — very likely the held file
  renamed and edited between two scans, which the scan reads as a delete plus a
  creation — waits, unsent, and so does the held record's delete; the issue says
  the file may be the vault file under a new name. In a vault it goes up sealed
  once the held record's delete has landed.
- A file from the server that wants the held file's name here waits for it, and
  says so; a new file saved into the vault under the name the held file still
  holds there takes a conflict name, and so does one saved there before the
  held file came to hold that name, while it has not yet been sent.

A vault folder dragged *out* is not converted: that would publish its contents
in the clear on the strength of a drag. The client leaves the server's copy
encrypted where it is, and raises an issue naming the folder. A folder inside a
vault is told it stays in its vault on the server and is kept only on this
device, and that it can be moved back into the vault, or its files downloaded in
the browser and uploaded where they are wanted; a vault root is told that a
vault sits only at the drive root or inside another vault, which is the server's
rule (`protection_boundary`). The two sides then disagree about where
that folder lives until the user acts — deliberately, and visibly, rather than
by a conversion nobody asked for. A device holding no vault key converts
nothing in either direction — the entry waits in `pending_key` rather than
trashing a copy it has no way to replace.

The old copy goes to the trash rather than away, which is what trashing the
source means; emptying it stays the user's decision.

Not yet built: encrypted thumbnails.

### The vault key

The key that opens encrypted content is the Drive vault's X25519 secret key. It
reaches the device once, during the browser link ceremony, sealed to a keypair
the device generated for that purpose, and goes straight into the operating
system's credential store — or, on a computer with no store the client can use
(Linux in the default build), a mode-0600 file in the state directory. A session
that cannot open a credential store the computer has is not given it at all
([Credentials](#credentials)). The daemon reads it back once at startup and
holds it for the life of the process — reading it per file would mean a
keychain prompt per file on macOS.

Only the secret half is stored. The public half — which is what file-key grants
are sealed to, because the sealing scheme mixes it into its key derivation — is
recomputed from the secret on every start. A pair carried as two stored fields
can be got out of step by one bad write, and the symptom is every unwrap failing
with an error that names nothing.

A device with no vault key is an ordinary, supported state, not a degraded one:
an account with no encrypted folders never needs one. So none of its failure
modes are fatal. A key that is missing, unreadable, or not a vault key at all
produces a warning naming which of the three it is, and the daemon starts and
syncs everything else.

**A vault key comes only from the computer's own link.** A link that hands no
vault key over — the box not ticked, a session that cannot keep one, a key that
would not open — removes any vault key the computer held from an earlier link,
so a key of another account, or of another installation, is never carried into
this one. At start the daemon uses a stored key only when its config says the
link handed one over (`vault_enabled`), and asks the server once
(`vault_client_probe`) whether the key can be the account's:

- the account has no vault: the key is not its, and is not used;
- the device row says this computer holds the account's key, and the one here
  has another public half: it is not the key this computer was handed, and is
  not used;
- the account's key is another one and this computer is no longer listed: that
  is a rotation, the retired key still opens what it opened before, and it is
  used (**A rotated vault key is noticed**, above);
- the server cannot be asked or will not say: the key is used, and the log says
  so.

A key that is not used is said in the log, with the way to fix it: link again
from the desktop session. Encrypted folders then read as not enabled here.

### Unicode normalization

Names are composed at one boundary — `Vfs::read_dir` returns NFC, always —
because a volume that hands a name back in a different normal form than it was
written reads as a rename on the very next scan, and two devices then rename the
file at each other forever.

**APFS does not decompose.** HFS+ normalized every name to NFD on write, and
"macOS decomposes your filenames" became folklore that outlived the filesystem;
APFS, which replaced it in 2017, stores exactly the bytes it is given and only
*compares* insensitively. `Personality::macos()` says so. Volumes that do
decompose are still real — HFS+ disks, Time Machine drives, network shares — and
`Personality::hfs_plus()` models them; the engine cannot tell which it has except
by probing, and does not need to.

**A FAT disk on a Mac keeps a spelling it never shows.** macOS's FAT driver
lists every name decomposed, finds a file by either spelling, and renames a file
(which a trash also is) only by the spelling its name was written with. The
Mac's file manager, Finder's with it, always asks by the decomposed spelling. So
where a disk lists names decomposed and finds them by either spelling, every
name the engine writes there (a folder, a download, a rename's new name) is
written decomposed, as Mac apps write one. A rename the disk answers "not found"
is asked again under the name's other spelling, only on a disk that takes both
spellings for one file: anywhere else the other spelling is another file. A file
still written composed that the Trash refuses is respelled decomposed, which
nothing on such a disk can see, and trashed again.

### macOS

FSEvents reports *resolved* paths, with symlinks followed. A watcher started on
`/var/…` receives events under `/private/var/…`, discards every one of them, and
reports itself perfectly healthy. So the root is resolved once, up front, and
never spelled any other way.

A file goes to the Trash through `NSFileManager`, never by asking Finder. Finder
takes the request as an AppleEvent, which a background daemon has no
permission to send: every trash would wait two minutes and fail. The one thing
given up is "Put Back", which some macOS versions leave off a file trashed this
way; the file is in the Trash all the same.

### Windows

Every filesystem call goes out as an extended-length (`\\?\`) path, so the
260-character limit does not apply; the real ceiling is the Win32 one. The
recycle-bin API is a shell API and does not understand that prefix, so paths are
converted back before they reach it.

File identity is the volume file index, from `GetFileInformationByHandle` on a
handle opened with `FILE_READ_ATTRIBUTES` and full sharing. The stand-in
everybody reaches for instead — creation time — is preserved across moves and
copied onto restored files, so unrelated files share one and the pairing logic
calls them the same file.

### Watcher backends

`notify` provides inotify, FSEvents, and `ReadDirectoryChangesW`. Every one of
them can drop events under load, and none can tell you afterwards that it did
not, so the stream is treated as a **hint** and never as truth:

- Events mark paths dirty; the truth is always the filesystem itself.
- A path is examined only after it has been quiet for 2 s, which turns an
  application's write-temp-rename-touch storm into one look at one file.
- A backend reporting it fell behind marks **nothing** dirty. It raises a
  rescan request, because we do not know what was missed and guessing would be
  worse than admitting it.
- A freshly started watcher requests a full scan immediately: nothing that
  changed while the client was closed produced an event.
- A full walk runs every 24 hours regardless, as the floor under all three
  backends.

---

## Crash consistency

The client may stop at any instruction.

**Downloads** stream to a spool file, are verified, then `fsync`ed and renamed
onto the target. A partial download is never something a user can open. The
rename is guarded by the fingerprint the engine decided against, so a file
edited while the download was in flight is not overwritten — the download is
withdrawn and the local edit wins. A file the user saves at a download's name
before that download has ever landed here is a new file of the user's, never
the download's edit: a rename this device sends for a file not yet downloaded
moves it on the server alone. A download's byte count comes from the bytes
that reached the spool, never from a header. It lands in the directory that
stood at its folder's path when the spool was opened, and only where that
directory is its folder's own: on a volume whose identities hold, a directory
at the folder's name with another id and birth (the folder renamed away and a
new one made at its old name) is another folder, and the download is refused
there. A folder the user moved or replaced while the bytes were in flight is
never made again at its old name (`VfsError::FolderMoved`), and a spool whose
folder is not on the disk when it opens is refused (`VfsError::NoFolder`). No
engine write makes a directory it was not asked to make: a move refuses a
destination folder that is not there, and a folder create makes one level,
never a missing parent. The download or move stands down and the next pass
places it where the folder now is.

The spool lives with the state store, so a sync root on another volume (an
external disk, a second partition, a mount point inside the root) cannot take
the rename. The commit then copies the bytes beside the target under
`.jd-land-<op>-<token>` (never over a file already standing there), makes them
durable, asks every gate again, because the copy is the widest window the
commit has, and renames them into place on the target's own volume. A landing
name that outlives its commit is trashed by the next walk unless its op is
still open in the journal; the server still holds the bytes, so the download is
simply planned again.

**Uploads** commit their hash at init, read from the same open handle the
bytes are sent from. A file whose bytes change mid-upload fails verification
at completion and re-queues. After the transfer the fingerprint is
re-checked: unchanged means record the agreement, changed means leave the entry
pending so the newer content is still sent.

**Every mutating request carries an idempotency key journaled before it was
sent.** This is what makes the worst network fault survivable: the request
arrived, the server did the work, and the answer never came back. That is
indistinguishable from "never arrived" and demands the opposite response.

**Recovery on start** re-derives interrupted operations by re-checking both
sides rather than blindly re-running them. An operation a pass
[stood down](#standing-down) from mid-transfer needs none: it is already back
on the queue as it was.

**Losing the state store is survivable by design**: a fresh index walk plus a
full local scan, pairing by path and hash. Identical bytes are never
re-transferred, because an upload of a hash the server already possesses moves
no bytes.

---

## Health, and never stopping silently

Every entry is always in exactly one visible state — `synced`, `pending_*`,
`conflict`, `unsyncable`, `pending_key`, or `out_of_scope` — and those reduce to
one indicator:

| Indicator | Meaning |
|---|---|
| **Green** | Converged: everything is in agreement, deliberately not synced here, or waiting on a key that only arrives from elsewhere. |
| **Working** | Transfers in flight or queued. |
| **Attention** | *n* things need a person: name clashes, conflicts, a full Drive, ciphertext that failed to authenticate. |
| **Stopped** | Cannot sync at all: no server, dead credentials, the folder is missing, or paused. |

Three rules decide between them, and all of them are about refusing to look
better than things are. **Attention outranks working** — a cheerful spinner
running while three files cannot sync has hidden the three files. **Work waiting
on a backoff is work** — a queue held for fifteen minutes after a failure is not
an idle client. And **green still has to say what it is not doing**: files
waiting for a key are green, because nothing on this machine will finish them,
but the count rides in the summary rather than being rounded away.

The indicator is computed from the store on every request, not accumulated as
passes run. An incremented counter drifts, and one missed decrement means a tray
that spins forever, which teaches the user to ignore it.

Issues carry a sentence rather than a code: "Cannot be saved here: the name
differs from *Report.txt* only by capitalization, and this disk cannot tell the
two apart. Rename one of them." The most important one is the missing folder,
which leads with *nothing has been deleted*. A server message that is plainly
not written for a person — anything carrying a `SQLSTATE` — is replaced rather
than shown; the original stays in the issue's detail for diagnosis.

**An issue about a state is withdrawn when the state ends.** Most issues report
an *event* — something was moved aside, something was rescued, a piece of work
was given up on — and those stand until the user waves them away, because they
happened and no later state makes them untrue. The rest report a *state*, and
each is checked every pass and withdrawn the moment it stops being true.
Otherwise the user carries a permanent warning about a file that is now
perfectly fine, clearable only by hand, and it inflates the attention count the
tray reads from.

| Issue | Says | Withdrawn when |
|---|---|---|
| `unsyncable` | this disk cannot hold that name | the entry is no longer unsyncable for that reason |
| `name_refused` | the disk itself turned away the name the server gives an entry; its detail is that name | the server no longer gives the entry that name |
| `rename_held` | an entry with no key on this device was renamed elsewhere to a name this disk cannot give it, so it keeps its old name here; its detail is the server's name | the entry is no longer held at that name |
| `store_inconsistent` | items have no way back to the root, and the server's index does not have their folder either | nothing is left stranded |
| `reserved_prefix` | files here have names beginning `.jd-`, which the client keeps for its own working files | no such file is left; the list in it follows the disk |
| `renames_untracked_on_this_drive` | the standing notice on a FAT or exFAT drive | the sync folder is on a drive that keeps file identities |
| `trash_waits` | a folder's trash here waits for a child that cannot move yet | the trash goes through, or the folder stops being trashable |
| `park_waits`, `park_unfinished` | a park waits on something inside the folder; a park's finish was overtaken | the park gets past the wait; the park no longer stands |
| `directory_disagrees` | a folder's directory stands apart from its files | the directory is back at the record's path, gone, or proven the folder's own |
| `held_outside_the_vault` | a sealed file stands outside its vault on this disk | it is gone, or back where the server keeps it |
| `waits_for_a_held_file` | a file from the server waits for a name a held file stands at | the refusal or the hold ends |
| `name_of_a_sealed_file_held` | a plain file is not sent: its name is a sealed file's, on a drive that keeps no file identities | it no longer holds that name |
| `name_held_by_a_parked_vault` | a folder's rename waits for a name a parked vault holds on the server | the name is free, or the folder is back at its agreed name |
| `vault_on_a_weak_drive` | a vault is parked on a drive that cannot keep a file's identity | the drive reads as one that can |
| `vault_deleted_upstream` | a vault was deleted on the server while this device, which cannot open it, holds files in its directory | the directory is empty or gone, or the vault is restored |
| `private_not_synced` | a Private folder stays off this computer | the folder is Standard again |
| `metadata_unreadable` | this device holds a file's key but cannot read its name and content id | they open |
| `vault_relink` | the vault key was replaced in the browser and this computer holds the old one, so it needs linking again ([Encrypted files and folders](#encrypted-files-and-folders)) | the probe's key is the one this device holds again, the account has no vault set up, or this device holds no vault key |
| `keeps_failing` | work that keeps failing (below) | nothing is left at the backoff cap |
| `deletes_held` | deletes the mass-delete guard holds ([The mass-delete guard](#the-mass-delete-guard)) | nothing is held |

Two event issues are withdrawn too, when what they describe is undone:
`parked` (a copy was moved aside over a name clash) once the clash is resolved
and the entry released, and `withdrawn` (the server refused an operation) once
the operation is carried out.

What decides `unsyncable` is the entry's own `LocalStatus`, not the naming
pass's report of what it just did: an entry already settled as unsyncable is not
re-reported each pass, so reading that report as the full set withdraws a
complaint that is still true.

Work that keeps failing is a state too. Once an operation's backoff reaches its
fifteen-minute cap it is tried every quarter of an hour for as long as it
fails, and one `keeps_failing` issue says how many operations are stuck and why
the first of them fails; it is withdrawn on the first pass that leaves nothing
at the cap. It is one issue for the device, not one per operation: an outage on
the server fails every queued operation at once, and a thousand identical
issues would hide the one that matters.

**A record of something the server has deleted is never a fault.** An entry with
no way back to the root normally means this device has lost its record of a
folder that still exists, which is a hole in the store's picture: the engine
re-derives from the index, and says so as `store_inconsistent` if the index
cannot account for the parent either. Entries carrying `remote_deleted` are
excluded from that count. There is no path to resolve for them and no work to
plan against them, and counting them starts a loop rather than a repair —
`drive_index` returns trashed entities on purpose, so the walk re-absorbs the
tombstone that provoked it and the next pass finds it stranded again, walking
every entity on every pass for the life of the client and standing on a claim
that items need attention when all of them are already deleted.

The records themselves are kept. Discarding them was tried once and the soak run
that followed lost seven files where the run before it lost none — they are what
ties a local file to what the server already holds. They stop being counted, not
stored.

**Stopping work is not the same as raising an issue.** An operation that cannot
proceed ends one of two ways. It is *withdrawn* when the server refused it in a
way another attempt will not change, and that is raised: somebody has to decide
what happens next. It is *overtaken* when the premise simply stopped holding —
the file was deleted while it was uploading, the entry is gone from the server,
the download landed on a file that had changed underneath. Overtaken work is
dropped in silence, because nothing about it is anybody's problem and the next
pass plans afresh from what is actually there. Deleting a file you had just
saved is an ordinary thing to do and must not leave an item asking you to look
at it. Where an overtaken operation ran into something the user *does* need to
know about — a conflict, say — that thing reports itself in its own words.

**A refusal that stands is held, not asked again.** A refusal the server marks
`standing` (the same request against the same entity, target and account limits
is refused again: a quota, an owner-only trash, no write access; see
[Drive](drive.md#standing-refusals)) is recorded in the store's `refusals`
table: the action and its target, the entity on both sides, and the feed
position the pass decided at. Asked again with none of that changed, the server
answers the same, so the next passes do not plan it. The hold lifts the moment
any of it moves (an edit, a rename or a move here, a change on the server, any
change to the account, which is what frees room for an upload refused over
quota) or after an hour, the one change the feed does not carry, such as a
limit an administrator raised. One `withdrawn` issue says what was refused, in
the words of the latest refusal, and goes when the operation is carried out.
Any other refusal is withdrawn and planned afresh on the next pass, and a
transport failure, a timeout, a 5xx, a refused sign-in or an upload whose bytes
did not all arrive (`upload_incomplete`) is retried. An edit sent back to a
folder that is leaving this computer, refused there, is not held: there is no
upload of the user's to hold and no path to hold it at, so the bytes stay as
`name (unsynced local edit).ext` at the top of the sync folder, and go up from
there as the account's own.

A `parent_trashed` refusal is read as overtaken for exactly that reason. The
destination folder went to the server's trash while this device was working
towards it, which is somebody else's delete arriving rather than anything wrong
here: the same index walk that carries the delete trashes this device's copy of
the folder, and work inside it the server never took is rescued out first and
raised as `rescued_from_trash`. That includes a file the user moved in here
whose move never landed: the server has the file, but somewhere else, so it is
carried out beside the folder and its move goes up from there. A rescued file's
record goes with it, so the file is never nobody's -- into a folder the server
has deleted as well, where one deleted folder stood inside another, whose own
trash rescues it again and the record follows on. A file saved in a vault and
carried out of it before it was sent stays held wherever it lands. Reading the
refusal as a failure instead would retry it forever against a folder that is not
coming back. Sealed work is rescued only into a folder that is itself in a
vault, from where it goes up sealed; carried out of the vault it would go up in
the clear, so there it stays and goes to the trash with the folder, and
`sealed_not_rescued` says so.

That local trash waits for one thing: a child the server has moved elsewhere
whose directory is still inside the folder here, because its new name cannot
be placed on this disk yet. It is neither what the server took nor what the
server never had, and trashing it with the parent would leave a record with an
agreement and no directory, which the next pass reads as the user deleting it.
The trash is retried until the child has been moved here, the same rule the
server-side trash applies to a move still on its way out. When the child cannot
move on this device until something else changes — no key for it here, out of
scope, a name this disk cannot hold — the wait is said as a `trash_waits`
issue naming the folder and the child, and withdrawn the moment the trash goes
through.

A `file_trashed` refusal answers the same question one level down: the file
this is a new version OF has gone to the trash. An operation planned before
that delete is dropped as overtaken rather than sent, so reconcile can apply
the rule that actually fits — an edit beats a delete, and the rescued bytes go
up as a new file where the old one lived. Sending it anyway is what makes two
devices disagree forever: the bytes land in a hidden row, the file appears to
come back on the uploading device, and the device that deleted it has already
dropped the tombstone, so nothing ever tells it otherwise.

The refusal carries `folder_id`, and that is the only folder the client records
as gone. It cannot work the answer out for itself: an operation may send a
destination it re-resolved at the moment it ran — `create_remote_folder` reads
the parent from the entry rather than from the plan, because a parent that was
provisional when the work was planned may be real by the time it runs — so the
plan and the request can name different folders. A refusal that names no folder
is not evidence about any folder, and the client records nothing; the deletion
arrives through the change feed like any other.

The server side of the same promise is `sde_last_seen_time`: the security page
shows when each device last synced, so a stalled device is visible from every
other device.

---

## Linking a device

Authentication happens in the browser, not the terminal. A passkey-first account
has no password to type, a step-up challenge cannot be answered at a prompt, and
the vault key can only be unlocked where WebAuthn works.

1. The client generates an X25519 keypair — **before** the request, because its
   public half is what an enabled vault key comes back sealed to. A session that
   cannot keep a vault key ([Credentials](#credentials)) generates none and
   sends no public key, so the approval page does not offer encrypted folders.
2. `POST /api/v1/auth/device_link` returns a code, a poll token, and a URL. The
   client opens the URL and prints both, because the ceremony works just as well
   with a person reading the code off one screen and typing it into another,
   which is the only way it works on a headless box.
3. The user approves in a signed-in browser, with recent step-up, optionally
   ticking "enable encrypted folders on this device".
4. The client polls every 3 s. On approval it receives the session key and, if
   enabled, the vault key sealed to its public key. **The credential is
   delivered exactly once** and scrubbed from the link row, so it is stored
   before anything else that could fail.

A denial or an expiry is an ordinary outcome, not an error — a user clicking
"not me" is doing what that button is for.

The ceremony is started one of two ways, and both store the approval through
the same code. `joinery-drive login <url>` runs it in a terminal. A daemon
started before the computer is linked runs it from the settings page instead:
the site's address, the approval in a signed-in browser (the page shows the code
and opens the approval page), then the folder to sync, then syncing starts in
the same process — see [The settings page](#the-settings-page).

---

## Where things live

| | macOS | Windows | Linux |
|---|---|---|---|
| Config | `~/Library/Application Support/com.joinery.drive/config` | `%APPDATA%\Joinery Drive\config` | `$XDG_CONFIG_HOME/joinery-drive` |
| State | same, `/state` | `%LOCALAPPDATA%\Joinery Drive\state` | `$XDG_STATE_HOME/joinery-drive` |
| Logs | `~/Library/Logs/com.joinery.drive` | `%LOCALAPPDATA%\Joinery Drive\logs` | `$XDG_STATE_HOME/joinery-drive/logs` |
| Sync root | `~/Joinery Drive` | `~\Joinery Drive` | `~/Joinery Drive` |

State is `LOCALAPPDATA` on Windows on purpose: a roaming profile that copied one
machine's record of what it had agreed to onto another machine would have the
second act on the first one's memory.

Updates keep their own two things: `update.json` in the config directory (which
channel this computer follows; see [Updates](#updates)) and an `updates` folder in
the state directory (downloads, the start marker, what the updater remembers).

`JOINERY_DRIVE_HOME` overrides all three at once. One variable rather than three,
because three would allow a config pointing at one account and a state store
holding another — which reads as mass corruption on the very next pass.

### Credentials

Three secrets: the API secret, the device X25519 key, and (when enabled) the
Drive vault key.

- **macOS** — the login Keychain. **Windows** — the Credential Manager.
- **Linux** — a mode-0600 file in the state directory, by default. The Secret
  Service needs a desktop session and a build-time system package, and the
  daemon explicitly supports headless servers. Build with the `secret-service`
  feature on a desktop to use the keyring instead.

**Each installation keeps its secrets under a name of its own.** The credential
store is shared by everything one OS account runs, and two installations — a
personal drive and a work one, each with its own `JOINERY_DRIVE_HOME` — hold the
same three kinds of secret. So the service name an installation's items are kept
under is the client's name followed by a hash of where its state directory really
is (symlinks resolved): `com.joinery.drive.<16 hex digits>`. The prefix is a
fixed name, not the bundle id. Linking one never
touches the other's keys, and unlinking one leaves the other linked. The hash is
FNV-1a, spelled out in the code rather than borrowed from the standard library,
so it cannot change with the compiler. Moving an installation's state directory
by hand gives it a new name: keys it kept in a file move with the directory, and
keys it kept in the credential store are not found under the new name, so it is
linked again.

The shared name `com.joinery.drive` holds the items of an installation linked
under it. An installation with a config or a state store of its own and no API
secret under its own name or in its file looks there when a secret is not
found, and moves all three items it finds to its own name, deleting them from
the shared name, so nothing is left there for a second installation to read as
its own. A new installation (no config, no state store) and one with a secret of
its own both leave the shared name alone: what is there belongs to another
installation. The shared name holds one installation's items; of several that
look there, the first started takes them, and the others are linked again.

**A keychain is only available in a session somebody has logged in to.** Over
SSH, or at boot before a graphical login, the login keychain is locked and no
prompt can be shown to unlock it — macOS answers "user interaction is not
allowed". In such a session:

- The API secret and the device key go to a mode-0600 file, as on a machine
  with no store, so plain folders link and sync.
- **The vault key is not kept at all.** On a computer that has a credential
  store, the key to encrypted folders lives in it or nowhere. `login` (and the
  settings page's first run) offers no public key for the vault to be sealed
  to, so the approval page does not offer encrypted folders, and says to run
  `joinery-drive login` from the desktop session to enable them. A computer
  with no credential store at all keeps the vault key in a file like the rest:
  there is nowhere else for it to be.

**A secret is read from wherever it was written.** The client looks in the
credential store (when this session can open it), then the file, then under the
shared name. A daemon started from the desktop finds a key that `login` wrote
to a file over SSH. Writing a secret into the credential store removes any copy
of it in a file, so each secret has one copy: where it was last written.
`joinery-drive status` and the settings page say where the API secret actually
is, which is not always where a key written now would go — "in a file only your
account can read (this computer's credential store was locked when it was
linked)". Linking again from the desktop session moves it into the store.

What the client must never do is confuse a locked store with a missing
credential. A user who links from their desktop puts the secret in the Keychain;
a daemon that later starts where the Keychain is locked finds nothing in the file
and would report the credential as gone — sending them to replace something that
is sitting right there. So a locked store is a distinct answer with its own
advice: *start Joinery Drive from your desktop session after signing in*.
Autostart uses a LaunchAgent precisely because it runs inside the user's session,
where the Keychain is open. For the same reason `login` and `unlink` refuse,
before changing anything, when this computer's key is in a store the session
cannot open: neither could take the old key off the account or out of the store,
so each says to run it from the desktop session instead.

The file fallback's custody class is exactly that of `~/.ssh/id_ed25519`: safe
against another user on the box, worthless against someone who already has the
user's account. There is no honest way to do better with a file — encrypting it
with a key stored beside it protects against nobody — so the client reports which
custody it got, and `joinery-drive status` prints it.

Whether a real credential store exists is a **compile-time** question. With no
platform backend compiled in, the `keyring` crate hands back a working
in-process map: every runtime probe passes, and every secret is gone when the
process exits. A client built that way appears to link successfully and cannot
start afterwards.

### Starting at login

macOS gets a LaunchAgent, Windows a per-user `Run` value, Linux a systemd user
unit. All three come back from a crash and stay down when the user asked them to
stop, so `joinery-drive pause` is not a fight with the service manager. The
artifacts are generated by pure functions and asserted in tests, because none of
these fail loudly — a malformed plist simply never starts, and the user finds out
when their files are a week stale.

---

## The program

```
joinery-drive login <instance-url> [--device NAME] [--root PATH]
joinery-drive daemon
joinery-drive settings
joinery-drive status | issues | dismiss <id>
joinery-drive approve-deletes [held-set]
joinery-drive pause | resume | sync-now | stop
joinery-drive autostart on|off
joinery-drive check-update
joinery-drive unlink
joinery-drive --version
```

Everything except `login` and `daemon` is a request to the running daemon, so
every answer is what the daemon actually believes rather than a second opinion.
`status` exits non-zero when the client is stopped or not linked, so a
monitoring script does not have to parse prose.

`login` takes the same addresses the settings page does, checked by the same
code before anything is sent: a missing scheme is read as `https://`, and plain
`http://` is refused except to this machine itself (`localhost`, `127.0.0.1`,
`[::1]`), saying why — the credential the link hands over would cross the
network unencrypted. While it waits, it prints the approval address and the
code either way, and says "opened in your browser" only where there is a
desktop and a browser was asked to open it; otherwise it tells the person to
open the address themselves, on this computer or any other. `settings` opens the settings
page in the browser, and prints its address when there is no desktop to open
it on.

`daemon` runs whether or not the computer is linked. With no config it serves
the settings page's first-run setup instead of syncing, and starts syncing as
soon as the setup finishes — or as soon as `login`, run in a terminal, writes a
config. A config that exists but cannot be read is still refused at startup:
falling back to defaults would point a client that had been syncing one folder
at another, and the first pass would read that as everything having been
deleted.

`unlink` revokes the device's key on the server *before* forgetting it locally —
the other order leaves a live credential nothing on this machine can name, which
is exactly the key a lost laptop carries. It then removes every copy of the three
secrets: the file and this installation's credential-store items — items this
installation finds under the shared name are moved onto those when `unlink`
reads its key ([Credentials](#credentials)). It leaves the synced files alone.

`login` on a computer that is already linked replaces the link. The old key is
read before the ceremony starts and, once the new link is stored, used to take
the old device off the account (`auth/logout` with its own credential, which
removes its device row) — only then, so a ceremony that fails part way does not
cost the user the link they had. If the old key is not on the computer any more,
`login` says to remove the old device from Security on the web. A daemon that is
syncing is stopped first, rather than left running on a key about to be revoked;
one still in first-run setup picks up the new config by itself. Linking the same
drive into the same folder again (to turn on encrypted folders, say) keeps that
folder's settings: the excluded subfolders, the poll interval, and whether it
starts at login.

Unlinking also leaves the state store, so relinking to the same folder resumes
without reading everything again. The store records which site, folder and
account it is the record of, and a daemon whose config names a different one
moves it aside (`state.db.before-<ms>`) and starts fresh. Read against another
folder, the old record would say every file it had agreed on was deleted here,
and the engine would send those deletes to the server; read against another
account's drive, it would take every file it had agreed on as deleted there. The
account is the one the server names (`auth/session`) when the link is approved,
kept in the config as `account_id`. A link made before the account was recorded
is judged on the site and folder alone — except that `login` replacing it and
`unlink` ask the server with its key whose account it is, and write that into
the store, so the next link of another account still sets the old record aside.

### Two threads

The **sync thread** owns the state store. Nothing else ever opens that database:
a second connection deciding what the last agreement was, concurrently with the
first, is how two answers to that question come to exist.

The **control thread** answers the tray, the CLI, and the settings page, and
holds no database handle at all. It reads a snapshot the sync thread refreshes,
and posts commands into a queue the sync thread drains. A hung tray can slow
nothing down and corrupt nothing. The queue is drained between passes, so a
stop, a pause or an unlink is also said to the pass in progress at once, and it
[stands down](#standing-down) at its next safe point.

The **update thread** ([Updates](#updates)) holds no database handle either. It
reads the same snapshot, and posts one command: `Stop`, when an update is ready
to install (to the setup loop instead, on a computer not linked yet).

### The control channel

An HTTP server on `127.0.0.1`, on a port the kernel picks, plus a token in a
0600 file beside it (`control.json` in the state directory). Loopback is not a
permission boundary — any local process can connect — so the token is what
carries the permission. Requests without a credential are refused before the
body is read, and never reach the daemon's handler.

The port is kernel-chosen because a fixed one is a fight with whatever else
wanted it, and a second instance silently failing to bind is a daemon nothing can
talk to. Shutting down removes the endpoint file: a stale one sends a client to
whatever now holds that port.

Connections are served a few at a time (four), each with a fifteen-second
deadline. More than one because browsers open spare connections they may never
use, and one served at a time held the whole channel for the deadline; a ceiling
because a thread per connection without one is a thread per connection any
local process cares to open.

**The browser's way in.** The settings page is the one client that cannot send
the token — a browser adds no header to a page it is told to open — and the
token must never travel in a URL, where it would land in history and in browser
extensions' hands while staying good for the life of the daemon. So the page is
let in by a chain of things each worth less than the last:

1. A token holder (the tray, `joinery-drive settings`) asks `POST
   /settings/ticket` for a **ticket**: single-use, two minutes long, good only
   for the next step. At most eight are outstanding.
2. The browser opens `http://127.0.0.1:<port>/settings#t=<ticket>`. The ticket
   is in the fragment, which a browser never sends to a server or puts in a
   `Referer`. The page's script trades it at `POST /settings/session` (ticket in
   an `X-Joinery-Ticket` header) for a **session**, and takes it out of the
   address bar.
3. Every request the page makes carries `X-Joinery-Session`. A session ends
   after an hour unused, and with the daemon. It is never a cookie: a browser
   attaches cookies to requests other sites make, and attaches a custom header
   only where a page of this origin told it to.

The rest of the browser-facing rules:

- The page's three files (`/settings`, `/settings/app.css`, `/settings/app.js`)
  are the only thing served without a credential. They are compiled into the
  binary and hold no data; everything the page shows it asks for.
- Every request without the token must carry exactly one `Host` naming
  `127.0.0.1` or `localhost` on this port. That defeats DNS rebinding — a site
  pointing its own hostname at 127.0.0.1 still makes the browser send its own
  name.
- A request carrying a ticket or a session must come from the page's own
  origin: an `Origin` header, when present, must be `http://127.0.0.1:<port>` or
  `http://localhost:<port>`, and `Sec-Fetch-Site`, when present, `same-origin`
  or `none`. Another site's page, or another local web server's on a different
  port, is refused.
- No answer ever carries an `Access-Control-Allow-*` header, so no other site
  can read one or get a preflight approved. Every answer carries a content
  security policy (scripts, styles and fetches from the page's own origin only,
  no framing), `X-Frame-Options: DENY`, `nosniff`, and `no-store`.
- A query string is never a credential: a token or session in a URL is simply
  absent.

What each caller may ask is the daemon's routing table (`daemon::ROUTES`): one
line per request, with its method, whether it needs a linked computer, and
whether the settings page may ask it. Anything that changes something is a
`POST`. The tray and the CLI may ask everything; the page may ask what it has a
button for, and not `/stop`, which would take the page down with the daemon.

### The tray

`joinery-drive-tray` is deliberately the thinnest thing in the repository. It
asks the daemon for status, draws it, and turns clicks back into requests — it
holds no state, opens no database, and decides nothing. Every judgement about
what a state *means* is a pure function in `jd-shell/src/view.rs`, tested on
every platform, because a tray needs a desktop session and a human to look at it
and three copies of that reasoning would drift apart three different ways.

Linux uses the StatusNotifierItem protocol over D-Bus through `zbus` — pure Rust,
so a Linux build needs no widget toolkit and no system development package.
macOS and Windows use the native tray APIs.

The daemon is a separate process on purpose: sync has to keep running when the
desktop session restarts and on machines with no desktop at all. Quitting the
tray stops the tray, not the sync.

While the computer is not linked the menu is the way into the setup: a state
line that follows the setup's stage ("Waiting for approval — code ABCD-EFGH"),
**Set up Joinery Drive…**, and Quit. **Settings…** and the issues item open the
settings page with a fresh ticket (`jd_platform::control::settings_url`), the
issues item scrolled to the issues list.

### The settings page

A local page served by the daemon (`jd-daemon/src/settings/`: plain HTML, CSS
and JavaScript, no framework, nothing fetched from anywhere else) and opened in
the default browser. Everything shown is set as text, never parsed as markup:
issue summaries and folder names carry names another device chose.

Linked, it shows:

- the health indicator in its four states, the summary and any blocker, items
  settled, transfers waiting, and when the last pass ran; pause or resume, and
  check now (hidden while paused);
- the issues list, each with a Dismiss button, and the full count when the list
  is capped at fifty. Deletes the mass-delete guard holds come first, from the
  status answer's `held_deletes` even when the capped list leaves their issue
  out, with an **Approve these deletes** button that asks once more and then
  posts the `held_set` it was shown to `/approve-deletes`;
- the sync folder, and the folder tree read-only. Every folder syncs; a folder
  the engine keeps off the computer (a Private folder, a vault on a volume
  without file identity) is marked "not on this computer". The tree is
  published by the sync thread after a pass that changed something and capped
  at 2000 folders, saying so when it is cut short;
- the site, the computer's name, where its keys are kept, whether encrypted
  folders open here, and whether it starts at login (a toggle);
- an **Updates** section, when the status answer carries an `update` field;
- **Unlink this computer**, behind a confirmation. It revokes the key on the
  server, forgets it locally, and leaves the files where they are; the daemon
  then goes back to the first-run setup on the same page.

Not linked, it walks through the setup in the spec's order, driven by a setup
loop that stands where the sync thread would (`jd-daemon/src/setup.rs`), so the
control thread never waits on the network:

1. **Your site** — the address (a missing scheme is taken as `https://`; plain
   `http://` is refused except to this machine itself) and a name for the
   computer, which ends up in conflict-copy names and so keeps to characters
   every disk accepts.
2. **Approve** — the link code, the approval page opened in the browser (and a
   link to it), and polling every 3 s. A network failure while waiting is said
   and retried; a denial or expiry returns to step 1 saying which.
3. **Folder** — prefilled with the default sync root. Refused: the whole home
   folder, a disk's root, and anywhere that holds or sits inside the client's
   own config, state or logs — checked both as typed and as the path resolves
   through symlinks, so a link to the home folder is the home folder. A folder that already has things in it asks for
   confirmation first, because they will be uploaded. "Start Joinery Drive when
   I log in" is on by default.

The credential is stored the moment the approval arrives, because the server
hands it over once. What the folder step still needs (no secret) is kept in
`pending_link.json` in the state directory, so a daemon restarted before the
folder is chosen resumes at the folder. Cancelling after an approval revokes the
new key rather than leaving it live on the account. The same happens when
`joinery-drive login`, run in a terminal, links the computer while the page's
approved link is still waiting for a folder: login's credential replaces the
page's in the store, so the page's device is revoked with its own secret (held
in memory for this) instead of being left on the account with no key anywhere.
The credential store is opened when the user starts linking, so a fresh install
touches no keychain before the user has asked to link anything. In a session
that cannot open it, the page links plain folders and the folder step says why
encrypted folders are not enabled, and how to enable them
([Credentials](#credentials)).

---

## Updates

The client keeps itself current from one signed feed that every installation
reads, whatever instance it is linked to (spec D19): instances stay
zero-config, and only one place has to sign anything. The code is
`jd-daemon/src/update/` and, for everything that differs by operating system,
`jd-platform/src/install.rs`.

### The feed

One JSON manifest per channel, at `https://getjoinery.com/drive/updates/<channel>.json`,
with a detached signature beside it at the same address plus `.sig`:

```json
{
  "format": "joinery-drive-update/1",
  "channel": "beta",
  "version": "0.3.0",
  "minimum_supported_version": "0.2.0",
  "release_notes_url": "https://getjoinery.com/drive/releases/0.3.0",
  "artifacts": [
    { "os": "macos", "arch": "universal", "kind": "update",
      "url": "https://…/joinery-drive-0.3.0-macos-universal.update.tar.gz",
      "size": 9123456, "sha256": "…" },
    { "os": "linux", "arch": "x86_64", "kind": "deb", "url": "…", "size": …, "sha256": "…" }
  ]
}
```

The `.sig` file is base64 Ed25519 signatures over the manifest's exact bytes,
one per line. Exact bytes, because a signature checked against a
re-serialization is a signature over something else; more than one line,
because that is how a key is rotated (below).

Only `kind: "update"` is ever installed: the payload this client knows how to
put in place, which is the app bundle on a Mac and the program's files
elsewhere. The other kinds (`dmg`, `installer`, `deb`, `rpm`, `tarball`) are
there for the download page and are never fetched by the client. A Mac matches
its own architecture or `universal`; everywhere else the architecture must
match exactly, so a native ARM install is never replaced by an emulated x86 one.

`update.json` in the config directory chooses the channel (default `beta`),
can name another feed address (`feed_url`), and can turn off automatic
installing (`automatic: false` still checks and reports). Off means off, a
required update included — one the feed's `minimum_supported_version` says
this copy is below: the status leads with "A required update is waiting", and
it installs when somebody asks (`check-update`, or the settings page's button). It is apart from
`config.json` because it belongs to the installed program, not to the link:
unlinking forgets the account, not the channel. It is read when the daemon
starts. A damaged file is reported in the status and the defaults are used.

**When it looks:** 30 seconds to 5 minutes after the daemon starts, then every
six hours, each time at a random point an hour either side, so a fleet waking
at the same moment does not arrive at the feed together. After a failed check,
again in 30 to 60 minutes. `joinery-drive check-update` (control `POST
/check-update`) checks at once.

**What it believes, in order:**

1. The signature, against the keys compiled into the build. Nothing in an
   unsigned document is read, not even its version.
2. The channel inside the document must be the configured one. Every channel
   is signed by the same key, so without this a stable client served the beta
   document would accept it.
3. The version must be higher than the running one. It never installs a lower
   or equal version, so an old manifest replayed by anybody is inert. Versions
   are `MAJOR.MINOR.PATCH` with an optional `-alpha.N`, `-beta.N` or `-rc.N`,
   ordered as the server's upgrade gate orders them: a pre-release before its
   release, alpha before beta before rc.
4. The download's size and SHA-256 against the manifest, while it downloads,
   into a `.part` file renamed only once both match.
5. The payload's format (below), unpacked beside what it replaces.
6. The new program itself: the staged daemon is run once with `--version` and
   must print the version the manifest promised, before anything is swapped.
   The first line of `--version` is therefore a contract: its last word is the
   version (`joinery-drive 0.3.0`), and the wording changes only together with
   the check that reads it (`update::preflight`).
   This is what catches a program that cannot run here at all — the wrong
   architecture, a signature the system refuses, a missing library — which
   never reaches the code that would count a failed start.

### Keys

`jd-daemon/src/update/trusted_keys.txt` lists the Ed25519 public keys the
client accepts a feed from, one base64 key per line. It is in the repository so
that adding or retiring a key is a commit anybody can read, and so that nothing
a local process writes can teach the client to trust somebody new. A feed
signed by any listed key is accepted. A build whose list is empty checks no
feed, says so in its status, and installs nothing.

A rotation: add the new key to the list and ship a release; sign every feed
with both keys (`joinery-drive-release sign --append`) while clients move; then
remove the old line and stop signing with it.

### What each kind of install does

| Installed as | Judged from | Does |
|---|---|---|
| Mac app (`/Applications/Joinery Drive.app`) | the executable is `<X>.app/Contents/MacOS/…` and the bundle's folder is writable | replaces the whole bundle in one atomic exchange (`renamex_np` with `RENAME_SWAP`) |
| Windows program folder | the executable's folder is writable | renames the running `.exe` aside (Windows allows that, never overwriting it) and the new one into its name |
| Linux tarball | anywhere writable outside `/usr` (`~/.local/bin`, `/usr/local/bin`, `/opt/…`) | keeps the old file as a second hard link, then renames the new one over the live name |
| `.deb` / `.rpm` | under `/usr` (not `/usr/local`), `/bin`, `/sbin` | reports "Version X is available. Install it with the system package manager" and touches nothing |
| Microsoft Store, snap, Nix | `WindowsApps`, `/snap`, `/nix/store` | reports, touches nothing |
| A folder this account cannot write | the write probe fails | reports who has to install it |
| A cargo build directory | `target/debug`, `target/release` | not checked unless asked, never replaced, never restarted when rebuilt |

Writability is asked by creating a file, not read from permission bits, because
an ACL, a read-only mount or macOS's app protection can each refuse a folder
whose mode allows it. A Mac update replaces the bundle and never one file
inside it: a signed bundle with one file changed is a bundle whose signature no
longer holds. Each top-level entry in a Windows or Linux payload replaces the
file of the same name. A payload naming a file the install does not have is
refused, and so is one that leaves out a program the install has — the daemon,
and the tray where there is one — so the programs in one folder always come
from one release; the status says which file was missing.

### Installing is a restart

The update thread is a third thread beside the sync and control threads. It
holds no database handle. To install, it hands the staged update to `main` and
posts `Stop`; the pass in progress [stands down](#standing-down) at its next
safe point, and `main` swaps the files once the store is closed.

Then the daemon starts again from its live path:

- **Under the LaunchAgent or a systemd unit**, it exits with status 75, which
  both bring back (`KeepAlive` on an unsuccessful exit, `Restart=on-failure`).
  Supervision is judged precisely, because a daemon started by hand that exited
  expecting a restart would simply be gone: the LaunchAgent sets
  `XPC_SERVICE_NAME` to the bundle id, and a systemd unit's own process is the
  one `SYSTEMD_EXEC_PID` names (on systemd older than 248, a process in the
  `joinery-drive.service` control group). Any unit's own process exits to be
  restarted, even in a unit an administrator wrote: systemd stops everything in
  a unit when its own process exits, so a replacement it started itself would
  be stopped with it.
- **Otherwise** (Windows, whose `Run` value only runs at login, or a daemon
  started by hand) it starts `<exe> daemon` — the command autostart runs —
  detached, and stays to watch it come up.

A paused client is still paused after the update, from the first pass.

The tray watches its own executable and, once the file at its path is a
different file and has looked the same twice in a row, starts the new copy and
exits. The daemon does the same when its file is replaced underneath it — by a
package manager, or a new app dragged over the old one — once the new file has
answered `--version`. The second look keeps a half-written file from being
started.

### When the new copy does not start

Before the first file moves, the old daemon writes `updates/pending.json`:
the two versions, every swap, and whether syncing was paused. The new daemon
reads it before anything else it does and counts the start. It clears the
marker, and the old copy is deleted, once its sync loop has run the startup
recovery and published its first status — or, on a computer not linked yet,
once the first-run setup is answering.

- Under a supervisor, every start is an attempt. Past three, the new copy puts
  the old one back itself and exits to be restarted as the old one.
- Watched by the old process, a new copy that exits, or is not up within two
  minutes, is put back by the old process, which then starts itself again.
- A marker found mid-swap (the installing process died) is put back on the next
  start, from wherever the swap stopped; each platform's swap can be undone from
  any point. Windows has one gap: between renaming the old `.exe` aside and the
  new one into place, two system calls apart, there is no program at the live
  path.

The old copy that comes back says so in its status ("The update to X was
undone…") and does not install that version again until a newer one is
published or somebody asks with `check-update`.

### In the status answer

`GET /status` carries an `update` object once the computer is linked, which
`joinery-drive status` prints as one line and the settings page shows in its
Updates section, beside a **Check for updates** button (`POST /check-update`;
a check that cannot run, in a build with no key, answers 409 with the reason):

| Field | Meaning |
|---|---|
| `state` | `disabled` (no key, or an unreadable version), `idle`, `checking`, `downloading`, `installing`, `available` (newer, not being installed here), `failed` |
| `summary` | one sentence for a person |
| `current`, `channel`, `automatic` | this copy and its settings |
| `install`, `can_install` | how this copy was installed, and whether it replaces itself |
| `available`, `required`, `release_notes_url` | the newer version; `required` when this one is below the feed's `minimum_supported_version` |
| `detail` | why an available version is not being installed here |
| `last_check_ms`, `last_error` | the last check, and what went wrong |
| `updated_from` | the version this process replaced, once it is up |

### Publishing a release

`joinery-drive-release` (in `jd-daemon`, never shipped) does the publisher's
half:

```
joinery-drive-release keygen --out PATH                      # once per key
joinery-drive-release pack --out macos.update.tar.gz "Joinery Drive.app"
joinery-drive-release pack --out linux-x86_64.update.tar.gz joinery-drive joinery-drive-tray
joinery-drive-release manifest --channel beta --version 0.3.0 \
    --base-url https://getjoinery.com/drive/updates/files \
    --artifact macos:universal:update:macos.update.tar.gz \
    --artifact linux:x86_64:update:linux-x86_64.update.tar.gz \
    --artifact linux:x86_64:deb:joinery-drive_0.3.0_amd64.deb --out beta.json
joinery-drive-release sign --manifest beta.json --key PATH    # or JOINERY_DRIVE_UPDATE_KEY=PATH
joinery-drive-release verify --manifest beta.json --trust <public key>
```

The signing key comes only from `--key` or the file `JOINERY_DRIVE_UPDATE_KEY`
names; there is no default location. A key file other accounts can read is
refused, and so is one inside a git working tree that git would track. The
file is base64 of the 64-byte libsodium form (seed, then public key), the same
form as the platform's release key, so one file works here and with PHP's
`sodium_crypto_sign_detached`. `sign` checks its signature against the public
half before writing it, and refuses a manifest no client would accept;
`manifest` refuses to write one.

The payload is a gzipped tar in a subset this tool writes and the client
reads: plain ustar headers, folders, regular files, and Unix symlinks, with
sorted entries, zero times and owners, normalised modes, and a gzip header
with no name or time — so one build always packs to the same bytes. The
client refuses anything else by name, any path that leaves the folder it is
unpacked into, anything written through a link the archive made, and more than
2 GiB unpacked.

### Tests

The update modules carry unit tests in `cargo test -p jd-daemon` and
`-p jd-platform` (and so in `sync_engine_gate.sh`): manifest parsing; good,
bad, stranger's and tampered signatures; rotation with either key; the channel
check; downgrades; an unknown platform; the decision for each kind of install, including a required update waiting
while automatic updates are off; a folder update that leaves out the tray; the
built-in key list parsing;
the payload round trip, determinism, and every escape; each platform's swap
undone from every point; the start marker's count, rollback and confirmation.
`jd-daemon/tests/update_e2e.rs` serves a feed from 127.0.0.1 signed with a key
made for the test, carrying the real `joinery-drive` the build produced, and
checks that it is found, downloaded, run once, and swapped in over a stand-in
install (a program folder, or an app bundle on a Mac); that the real new binary,
started the way autostart starts it and unable to finish starting, puts the
stand-in back after three attempts; and that a tampered payload, a stranger's
signature, an older version, a package-managed install, and a binary that is
not the promised version all leave the install untouched.

---

## Testing

The edge-case space is combinatorial, so the client is built around a
deterministic simulator rather than a list of hand-written cases.

`jd-sim` provides a virtual filesystem with a per-OS personality (case-folding,
NFD, coarse mtimes, inode reuse, scheduled failures), a mock server implementing
the documented contract (cursor feed with resets, chunk resync, dedup, quota,
idempotency replay, declared modification times, key grants that arrive and are
withdrawn on the feed, a vault key rotation), a controlled clock, and a
network that loses, delays, reorders, and duplicates. Every run is reproducible from a seed, and seeds that
found bugs are frozen in the repository.

Two invariants are asserted around **every pass**, not at the end of a run:

1. **Convergence** — each device's disk and the server agree.
2. **Nothing committed is lost** — anything that was on a disk before a pass and
   is gone after it must still be reachable: the server's live tree, its version
   history, another device, a trash, or a conflict copy. The mock server keeps
   every version it has ever been given, which is what makes this a question with
   a checkable answer rather than a hope.

| Gate | Tier / env | Covers |
|---|---|---|
| `tests/functional/sync/sync_engine_gate.sh` | safe, needs `[rust]` | The pure decision-making: naming, reconciliation, ordering, the mass-delete guard, secret custody, the health model, the tray's presentation; the control channel's refusals and the settings page's routes, over HTTP on 127.0.0.1; and updates, end to end against a feed on 127.0.0.1 ([Updates](#updates)). |
| `tests/functional/sync/sync_sim_gate.sh` | safe, needs `[rust]` | Both harnesses themselves — a harness with a bug in it reports a clean run rather than a problem. |
| `tests/functional/sync/sync_cross_build_gate.sh` | safe, needs `[rust]` | The per-OS code compiles for Windows and macOS. |
| `tests/functional/drive/sync_crypto_parity_gate.sh` | safe, needs `[rust, node]` | Rust and the browser produce bytes each other can read. |
| `tests/functional/drive/sync_contract_test.php` | db, dev-only | The server surface the client depends on. |
| `tests/functional/sync/sync_macos_gate.sh` | live, needs `[macmini, rust]` | Built and green on a real Mac; the volume probe, NFD round trip, and Keychain persistence across processes. Run over SSH, where the login Keychain is locked, it checks that the vault key is refused rather than written to a file; the checks that two installations keep separate items and that items under the shared name move over need an open Keychain (a desktop session), and say so when they have nothing to check. |
| `tests/functional/sync/sync_soak_gate.sh` | live, dev-only, needs `[rust]` | One bounded storm-and-settle cycle on the soak rig: two real daemons, real persona actors, real kills, then the six settle assertions. |
| `tests/functional/drive/ranged_download_gate.sh` | live, dev-only | Range semantics over HTTP. |

The cross-build gate is what makes per-OS work verifiable from a Linux box at
all. It does not run anything; it catches the failure that actually happens to
code nobody builds, which is that somebody edits a shared path and the branch
they cannot compile stops compiling — found six weeks later by whoever tries to
cut a release. Behavior is covered instead by the simulator's per-platform
scenarios, which run the shipping engine over macOS and Windows filesystem
personalities on Linux.

## The soak environment

The simulator proves the engine's *logic*. It cannot see a real kernel, a real
server, or real time. The soak rig is the other half: the shipping daemon,
unmodified, on real disks, against a real Joinery instance, driven by
application write patterns that break sync clients, with real faults injected on
a schedule, for weeks.

It lives in `{repo root}/sync/jd-soak/` and stands up on a dedicated VPS —
never the box people work against, because the rig needs liberty to restart
services, purge feeds and wipe, and because weeks of synthetic load do not belong
on a working instance. `{repo root}/sync/soak/README.md` is how to run one.

### What the rig is made of

Four roles, and the split between them is the design.

**Actors** are the applications, reproduced faithfully enough to break things.
Each is a small state machine emitting the *shape* of writes a particular real
program makes, because the patterns that have historically broken every sync
client are not random writes — they are storms with structure. `office` drops a
`~$doc.docx` lock beside the file, writes `tmpNNNN.tmp`, renames it over the
original and removes the lock. `photoshop` deletes the original *before* its
replacement lands. `browser` grows a `.crdownload` for minutes and sometimes
abandons it. `messy-human` renames a folder while files inside it are still being
written. `remote-user` never touches a disk at all: it drives the API directly,
so its changes arrive at every device as remote deltas.

A persona is a pure function of its own state and its random draw — it emits
operations and never touches a filesystem. Applying them, journaling them, and
coping with the disk saying no is the executor's job. That split is what makes
the personas testable without a disk.

**The chaos agent** injects faults from outside the daemon — signals, firewall
rules, unit restarts. Nothing needs a cooperating build, which is the point: a
daemon compiled with soak hooks in it is a different program from the one that
ships. Every fault is journaled, including the ones that could *not* be
injected, because a campaign reporting a hundred kills it never performed is a
green run over an adversary that was never there.

**The verifier** settles the world and checks the invariants without asking the
daemon whether it is well. It uses the daemon's status for exactly one thing —
knowing when it has stopped working, so there is a stable world to look at — and
then forms its own opinion by walking every disk and the server's index itself.

**The conductor** alternates storm segments (45 minutes, actors and chaos
together) with settle segments (15-minute deadline), rotates the persona mix, and
freezes the world into a forensics bundle when an invariant breaks.

### The oracle

Three independent journals, written by parties that do not read each other: what
the actors committed, what the chaos agent broke, and what the verifier
concluded. An **intent** goes down before each filesystem operation and a
**commit** only after it returned success; the oracle believes commits and
nothing else, so an actor killed mid-write reads as an operation nobody can say
happened rather than as data loss.

### The six settle assertions

1. **Convergence within the deadline.** A stall is a failure, not a wait — the
   promise is "never silently stop", so a client quietly still working an hour
   later is a first-class bug even with nothing lost. A surfaced issue is a
   legitimate resting place; a spinner is not.
2. **Green is independently audited.** Every device's disk against the server's
   index, diffed by the verifier rather than by the thing under test. Names are
   compared through each volume's own rules, so a device is never failed for
   obeying its own disk.
3. **No loss.** The last content committed at every live path must still be
   findable on a device, in the server's head or version history, in a server or
   local trash, or as a conflict copy. Separately, every content **the server was
   observed to hold** must still be there — once it has taken a content it
   promised to keep it, and this instance keeps every version on purpose.

   What is deliberately *not* asserted: that every intermediate local save
   survives. A file written and written over again seconds later, before any
   client could upload it, is a file the user overwrote — no sync client captures
   every keystroke of a save, and demanding it produced thousands of violations
   that were the rig complaining about its own actors typing quickly.
4. **Ciphertext never materializes.** No sync root holds bytes only the server
   was meant to see, and every encrypted entity a device cannot open is
   *visible* as such rather than silently absent. With no encrypted entities in
   play this passes as **vacuous** and says so — a week-long campaign reporting
   six green assertions with its encrypted lane switched off would otherwise look
   identical to one that tested it.
5. **Issues honesty.** Every entry that will never proceed on its own —
   unsyncable, waiting for a key — has a surfaced reason with its name on it.
   Work still in flight does not: a draining queue is what a working client looks
   like, and demanding an explanation per file in it would mean an alert per file
   in a healthy client.
6. **Leak watch.** Memory, descriptors, spool residue and store size, sampled
   every settle. Only a number that has never once come down over a day of
   storms is worth reporting; a store that grows with the tree is doing its job.

### Reading a report and a bundle

`{base}/journal/report.txt` is rewritten every settle, and `jd-soak report`
rebuilds the same document from the journals on demand. The first line is
**INVARIANT VIOLATIONS**, which must read 0. Convergence is reported at p50, p95
and max rather than as a mean, because a client that converges in four seconds
ninety times and eleven minutes once has a problem a mean hides completely.

On a violation the world is frozen into `{base}/bundles/violation-cycle-N-…/`:
all three journals whole, every device's state store, config, daemon log and
tree listing, the verdicts, and `timeline.txt` — every actor operation and fault
on one line of time, with faults marked so the one in flight when a file was last
seen can be found by eye.

That correlation is what replaces seed replay, because there is none: no seed
reproduces a real kernel and a real network. The next step after reading a
timeline is always the same — **encode it as a frozen `jd-sim` scenario**, so the
bug becomes a fast deterministic regression instead of something that might
happen again in a fortnight. The soak rig finds bugs; the simulator owns them.

### Running a drill by hand

When something is wrong and a whole campaign is the wrong instrument:

```bash
jd-soak actor  /soak/fleet.json --device device-a --persona office --seconds 120
jd-soak chaos  /soak/fleet.json --device device-a --fault partition --seconds 60
jd-soak verify /soak/fleet.json     # one settle, six assertions, no storm
```

`verify` exits non-zero when an invariant is broken, so it stands alone as a
gate.

### Why the devices are host processes

The daemon publishes its control channel on loopback, on a kernel-chosen port,
which is right — binding it to every interface would put a client's sync controls
on the network. It also means a daemon inside a container cannot be asked
anything from outside it, and the verifier has to be able to ask. So each device
is an ordinary process under **its own unix account**, supervised by systemd
(`Restart=always` is what turns `kill -9` into reboot semantics). The account is
what makes a per-device network fault possible: `iptables -m owner --uid-owner`
cuts one daemon's traffic to the server and leaves its neighbours syncing.
