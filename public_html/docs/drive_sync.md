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

---

## One pass

The client is this loop, run over and over:

1. **Read the change feed** from the stored cursor (`drive_changes`), or walk
   the whole index (`drive_index`) when the server answers `{reset: true}`.
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

---

## Deciding what happens

Per entry, each side's change is one of: none, edited, created, deleted, or
moved. Content and location are separate axes, so a remote move and a local edit
compose into "apply the move, then upload" rather than fighting.

Three rules run underneath the whole table:

**An edit beats a delete, in both directions.** They are not symmetric outcomes:
a delete that loses is recoverable from a trash, an edit that loses is gone. So
the edit survives, even where that resurrects a file somebody meant to remove.

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
lands in the issues panel.

### The mass-delete guard

If one round would delete more than `max(50, 25%)` of the settled entries — in
either direction — that class of operations is withheld and a person is asked.
Ransomware, a volume that mounted empty, and a legitimate cleanup are
byte-for-byte identical from here, and there is no clever test that separates
them.

Only deletes are withheld, and only in the blocked direction; ordinary transfers
in the same round still run. An unavailable sync root is a separate case: it
hard-pauses rather than reading as a mass local delete.

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

Which sibling wins: anything already on this disk claims first, then the lowest
server id. The consequence, stated honestly — two devices that downloaded a
clashing pair in different orders keep different members of it. Both remain on
the server and both devices report the clash. An entry recovers by itself, with
no user action, as soon as the clash clears.

### Encrypted files and folders

This section is about **Fortress** folders — the client-custody level, where the
device holds the keys. **Private** folders are excluded from sync entirely: their
bytes are opened by the server inside the owner's unlock window, and a headless
daemon has no window to open. Both exports carry `protection_level` and a
`syncable` flag, and a client skips anything marked unsyncable; the Drive UI says
so on the folder rather than leaving a member wondering why files never arrive.

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
goes to the server is the placeholder name, the ciphertext size, no content
hash, the metadata blob, and the file key sealed to every reader of the
destination folder (resolved through `drive_public_keys`). The owner's key is
mandatory: a vault file its owner can never open would be a file they can see,
are billed for, and have permanently lost.

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
An entry leaves `pending_key` by itself the moment the key arrives.

**Renaming** an encrypted file is a metadata re-encrypt, not a rename. The
current blob is fetched, decrypted, its name field changed, and the result
encrypted again under the same file key — the server stores it without learning
either name, and refuses a plaintext one outright. The blob is fetched rather
than rebuilt from what this device remembers, because another device may have
changed the mime type, the size or the thumbnail flag since, and rebuilding
would discard whatever this one did not know about. Moving a file *within* a
vault is an ordinary `drive_move` on ids: nothing re-encrypts. A vault folder's
own name is plaintext on the server and renames normally.

Not yet built: encrypted thumbnails, and converting a file across the
plaintext/vault boundary by moving it (the server refuses an in-place crossing;
the client has to re-upload and trash the source).

### The vault key

The key that opens encrypted content is the Drive vault's X25519 secret key. It
reaches the device once, during the browser link ceremony, sealed to a keypair
the device generated for that purpose, and goes straight into the operating
system's credential store. The daemon reads it back once at startup and holds it
for the life of the process — reading it per file would mean a keychain prompt
per file on macOS.

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

### macOS

FSEvents reports *resolved* paths, with symlinks followed. A watcher started on
`/var/…` receives events under `/private/var/…`, discards every one of them, and
reports itself perfectly healthy. So the root is resolved once, up front, and
never spelled any other way.

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
withdrawn and the local edit wins. A download's byte count comes from the bytes
that reached the spool, never from a header.

**Uploads** commit their hash at init. A file that changes mid-upload fails
verification at completion and re-queues. After the transfer the fingerprint is
re-checked: unchanged means record the agreement, changed means leave the entry
pending so the newer content is still sent.

**Every mutating request carries an idempotency key journaled before it was
sent.** This is what makes the worst network fault survivable: the request
arrived, the server did the work, and the answer never came back. That is
indistinguishable from "never arrived" and demands the opposite response.

**Recovery on start** re-derives interrupted operations by re-checking both
sides rather than blindly re-running them.

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
which leads with *nothing has been deleted*.

The server side of the same promise is `sde_last_seen_time`: the security page
shows when each device last synced, so a stalled device is visible from every
other device.

---

## Linking a device

Authentication happens in the browser, not the terminal. A passkey-first account
has no password to type, a step-up challenge cannot be answered at a prompt, and
the vault key can only be unlocked where WebAuthn works.

1. The client generates an X25519 keypair — **before** the request, because its
   public half is what an enabled vault key comes back sealed to.
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

**A keychain is only available in a session somebody has logged in to.** Over
SSH, or at boot before a graphical login, the login keychain is locked and no
prompt can be shown to unlock it — macOS answers "user interaction is not
allowed". The client falls back to a file there, which is correct, and says so.

What it must never do is confuse that with a missing credential. A user who links
from their desktop puts the secret in the Keychain; a daemon that later starts
where the Keychain is locked would find nothing in the file and report the
credential as gone — sending them to replace something that is sitting right
there. So a locked store is a distinct answer with its own advice: *start Joinery
Drive from your desktop session after signing in*. Autostart uses a LaunchAgent
precisely because it runs inside the user's session, where the Keychain is
open.

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
joinery-drive status | issues | dismiss <id>
joinery-drive pause | resume | sync-now | stop
joinery-drive autostart on|off
joinery-drive unlink
```

Everything except `login` and `daemon` is a request to the running daemon, so
every answer is what the daemon actually believes rather than a second opinion.
`status` exits non-zero when the client is stopped, so a monitoring script does
not have to parse prose.

`unlink` revokes the device's key on the server *before* forgetting it locally —
the other order leaves a live credential nothing on this machine can name, which
is exactly the key a lost laptop carries. It leaves the synced files alone.

### Two threads

The **sync thread** owns the state store. Nothing else ever opens that database:
a second connection deciding what the last agreement was, concurrently with the
first, is how two answers to that question come to exist.

The **control thread** answers the tray, the CLI, and the settings page, and
holds no database handle at all. It reads a snapshot the sync thread refreshes,
and posts commands into a queue the sync thread drains. A hung tray can slow
nothing down and corrupt nothing.

### The control channel

An HTTP server on `127.0.0.1`, on a port the kernel picks, plus a token in a
0600 file beside it (`control.json` in the state directory). Loopback is not a
permission boundary — any local process can connect — so the token is what
carries the permission. Requests without it are refused before the body is read.

The port is kernel-chosen because a fixed one is a fight with whatever else
wanted it, and a second instance silently failing to bind is a daemon nothing can
talk to. Shutting down removes the endpoint file: a stale one sends a client to
whatever now holds that port.

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

---

## Testing

The edge-case space is combinatorial, so the client is built around a
deterministic simulator rather than a list of hand-written cases.

`jd-sim` provides a virtual filesystem with a per-OS personality (case-folding,
NFD, coarse mtimes, inode reuse, scheduled failures), a mock server implementing
the documented contract (cursor feed with resets, chunk resync, dedup, quota,
idempotency replay), a controlled clock, and a network that loses, delays,
reorders, and duplicates. Every run is reproducible from a seed, and seeds that
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
| `tests/functional/sync/sync_engine_gate.sh` | safe, needs `[rust]` | The pure decision-making: naming, reconciliation, ordering, the mass-delete guard, secret custody, the health model, the tray's presentation. |
| `tests/functional/sync/sync_sim_gate.sh` | safe, needs `[rust]` | Both harnesses themselves — a harness with a bug in it reports a clean run rather than a problem. |
| `tests/functional/sync/sync_cross_build_gate.sh` | safe, needs `[rust]` | The per-OS code compiles for Windows and macOS. |
| `tests/functional/drive/sync_crypto_parity_gate.sh` | safe, needs `[rust, node]` | Rust and the browser produce bytes each other can read. |
| `tests/functional/drive/sync_contract_test.php` | db, dev-only | The server surface the client depends on. |
| `tests/functional/sync/sync_macos_gate.sh` | live, needs `[macmini, rust]` | Built and green on a real Mac; the volume probe, NFD round trip, and Keychain persistence across processes. |
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
