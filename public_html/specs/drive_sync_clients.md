# Drive Sync Clients — Skeleton Spec

**Status:** Skeleton / not yet scoped for build. Captures intent, the existing
foundation, the real gap, and open decisions. Flesh out before implementing.

## Why this exists

A self-hosted file suite lives or dies on its **sync client**, not its feature
list. The loudest, most repeated complaint about Nextcloud — across HN, Reddit,
and review sites — is not missing features; it is that **sync silently stalls,
mobile photo backup drops files, large uploads fail, and files occasionally
get lost or conflicted**. Users forgive a plain UI; they do not forgive a sync
engine they cannot trust with the one copy of a file. Many defect to Seafile
(faster block sync) or Immich (reliable photo backup) for exactly this reason
while keeping Nextcloud only for the parts nothing else covers.

The strategic opening: Joinery Drive can compete not by matching Nextcloud's
app breadth but by making **"a file put here is never lost, corrupted, or
silently un-synced"** the acceptance bar — the promise Nextcloud keeps failing.
And Joinery can do it while syncing **client-custody end-to-end-encrypted**
files, which Nextcloud's E2EE explicitly cannot: its encrypted folders are not
even browser-accessible, let alone cleanly syncable.

## Goal

Native sync clients (desktop first: macOS / Windows / Linux; then mobile
backup) that keep a local folder tree bidirectionally in step with a member's
Drive, including encrypted vault folders, with **correctness ahead of speed**:
never lose a local edit, never corrupt on conflict, never silently stop.

## Non-goals (for the first cut)

- Real-time collaborative editing (that is an Office-suite concern, not sync).
- Matching Nextcloud's full app catalog.
- Syncing anything outside the Drive `fil_source = 'drive'` boundary.
- LAN peer-to-peer sync. Server is the hub.

## What already exists (the foundation is most of the server contract)

The server side is far more complete than a greenfield estimate would assume.
`docs/drive.md` already documents a sync-oriented contract; a client consumes
it largely as-is:

- **Resumable chunked upload protocol** — `drive_upload_init` →
  `PUT /api/v1/drive_upload/{token}` (sequential `Content-Range`, 409-with-offset
  resume) → `drive_upload_complete`. Already described in-doc as "the complete
  server contract for sync clients." Idempotent complete, per-owner quota
  enforced at admit time, dedup-by-possession short-circuit (unchanged bytes
  never re-transfer).
- **Change feed with a cursor** — `drive_changes` takes `{cursor}`, returns
  visible changes after it (own + shared-to-me) with `next_cursor`; emits
  `{reset: true}` when the cursor precedes the retained window so a client
  re-lists instead of silently missing changes. Kinds: `created`, `content`,
  `renamed`, `moved`, `trashed`, `restored`, `deleted`, `grant_changed`.
- **Listing / tree ops** — `drive_list`, `drive_move`, `drive_rename`,
  `drive_trash`, `drive_restore`, `drive_versions`, `drive_version_restore`.
- **Download transport** — private files serve through short-lived signed URLs
  (`File::mintSignedUrl()`), usable without cookies from a native client.
- **Versioning** — new content demotes the head blob to a `FileVersion`; a
  bad sync write is recoverable, which lowers the blast radius of client bugs.
- **Client-custody E2E crypto contract** — file keys are wrapped to each
  reader's Drive vault public key and unwrapped only in-client. The browser
  Drive client (`drive-crypto.js`) already does this dance; a native client
  follows the same `FileKeyGrant` / sealed-DEK contract. See
  `docs/drive_encryption.md`.
- **Native app platform** — web-session bridge, navigation endpoint, iOS kit +
  member app, Android build box. A shell to host mobile backup exists; see
  `docs/mobile_apps.md`.

## The actual gap (this is where the work is)

Everything above is **server + browser**. What does not exist is the **native
client sync engine**, and that is the hard, reliability-critical part:

1. **Local state store** — a per-client database of every synced file's path,
   server id, last-synced content hash, version/cursor, and pending state.
2. **Local filesystem watcher** — detect create/modify/delete/rename/move on
   the OS (FSEvents / ReadDirectoryChangesW / inotify), debounced.
3. **Bidirectional reconcile loop** — pull `drive_changes`, diff against local
   state, decide per item: upload, download, rename/move, delete, or conflict.
4. **Conflict handling** — deterministic, non-destructive (never overwrite the
   losing side; materialize `name (conflicted copy — device, time).ext`).
5. **Encrypted-folder sync** — unwrap the file key in-client, decrypt on
   download / encrypt on upload, honor the encrypted-metadata rename contract.
6. **Selective sync** — let a client subscribe to a subtree, not the whole drive.
7. **Cross-platform packaging + auto-update** for three desktop OSes.

### Likely server-side additions (small, additive)

- **Resumable / ranged download** to mirror the resumable upload (HTTP `Range`
  on the signed-URL serve path) so a large-file pull can resume. Today's serve
  path streams; confirm/close the ranged-resume gap.
- **A sync-session handshake** the desktop client uses instead of a browser
  session — probably the app session key (already minted) plus a
  device-registration record for per-device revocation and conflict-naming.
- **Directory-scoped change subscription** if selective sync needs the feed
  filtered server-side rather than client-side.

## Edge cases the engine must handle

Sync reliability is hard for one root reason: it is a **three-way merge with no
merge tool**. At every moment there are three states — local now, remote now,
and the last common state both last agreed on — and correctness means inferring
what changed on each side from those three. The last-common state must be
persisted perfectly, because without it "the other side created this" and "the
other side deleted a file I still have" are indistinguishable. Every case below
is a variation on that ambiguity. (Context: Dropbox rewrote its entire engine
from scratch — Project Nucleus, Rust, ~2020 — to make this provably correct.)

**The filesystem is an adversary, not a database.**
- Case-sensitivity mismatch across OSes (macOS/Windows case-insensitive, Linux
  case-sensitive) — `File.txt` and `file.txt` collide on one client, not another.
- Unicode normalization: macOS stores NFD, Linux stores raw bytes — byte-different
  names that are the same name. Need one canonical form + reversible mapping.
- Illegal / reserved names per-OS (Windows `< > : " / \ | ? *`, `CON`/`NUL`/`AUX`,
  trailing dots/spaces, 260-char path cap). Need lossless, reversible name mangling.
- Special objects: symlinks/junctions (can escape the sync root or loop),
  hardlinks, sparse files, xattrs, resource forks, permissions/ACLs.

**Apps thrash files; the watcher must coalesce.** A single "Save" surfaces as a
storm of create/write/delete/rename events (Office, Photoshop, SQLite each have
their own dance). Coalesce into one logical change; never upload a half-written
intermediate.

**Change detection is unreliable at both ends.**
- `mtime` lies (moves backward, unchanged across real edits, precision differs:
  FAT 2s vs ext4 ns). Layer size+mtime as a cheap filter, content hash to confirm.
- OS event APIs drop events (inotify buffer overflow, FSEvents coalescing,
  network drives emit nothing) — event-driven watching must be backed by
  periodic full rescans, which is itself a perf problem at millions of files.
- Move vs. delete+create: the OS often reports a rename as unrelated delete +
  create. Re-pair by content hash / inode, or a moved 4 GB file re-uploads 4 GB
  and loses its share + version history. (Inode reuse makes pairing ambiguous.)

**Operations have dependencies and cycles.** Can't create a file before its
parent folder or delete a folder before its children — each batch is a
dependency graph to topologically order. Genuine cycles exist (A→B while B→A is a
swap needing a temp name). Renaming a 10k-file folder is ONE op, never
delete-and-recreate.

**Deletes are the data-loss footgun.**
- "Remote deleted this" vs. "never seen / outside my selective-sync scope" look
  nearly identical — misclassify and you propagate a delete of the only copy.
- Mass-delete / ransomware propagation: a faithful client replicates a disaster
  everywhere. Server-side version history + trash is the immutable backstop the
  client cannot bypass. The client never hard-deletes locally with <100%
  confidence — move to a local trash first.

**Multiple devices + skewed clocks.** Never order events by wall clock — use the
server's monotonic sequence. **Already solved in Joinery:** the append-only
change feed whose primary key IS the cursor removes this whole category.

**Scale fights correctness.** Millions of files without exhausting RAM (can't
hold the whole tree in memory), melting CPU, or draining battery; block-level
delta sync (rolling hash) so a 1-byte change in a 1 GB file isn't a 1 GB upload.

**Encryption is Joinery's specific tax** (the differentiator's cost):
- Delta/block sync operates on plaintext blocks; design the crypto envelope and
  the chunking together (content-defined, per-chunk encryption) or lose delta sync.
- Filenames/metadata are ciphertext — conflict-copy naming, case-collision
  detection, and illegal-name mangling happen in the encrypted domain / post-decrypt.
- Key-arrival races: a shared file whose `FileKeyGrant` hasn't propagated is
  "visible but not yet readable," not an error and not a delete.
- Mandatory integrity: AEAD-verify every chunk in-client; refuse tampered/corrupt
  ciphertext, never write it to disk where it looks like a local edit.

**Crash consistency.** The engine can die mid-operation at any point; the local
state store and the filesystem must never diverge (journaled / write-ahead
local state), and a partial download/upload must never surface as a complete file.

### What Joinery already defuses

Two of the biggest footguns are gone before the client is written: the
**server-assigned ordering cursor** (kills the distributed-clock problem) and
**version history + trash** (kills the catastrophic-delete problem). Resumable
transfer and dedup-by-possession are also already server-side. The remaining
hard parts — filesystem adversary, event coalescing, move detection, dependency
ordering, crash consistency, and encryption-aware delta sync — all live in the
client engine.

## Open decisions (resolve before build)

- **Client language / framework.** One cross-platform core (Rust or Go) with
  thin per-OS shells, vs. per-platform native. Reliability + shared crypto
  argue for one audited core. (Note: Nextcloud's own client is C++/Qt and is
  the thing users complain about — architecture choice matters.)
- **Conflict policy.** Last-writer-wins-plus-conflict-copy (Dropbox model) vs.
  always-branch. Non-destructive is non-negotiable; pick the exact materialization.
- **Encrypted sync as v1 or v2.** Syncing plaintext Drive folders is
  straightforward; syncing encrypted vault folders is the differentiator but
  adds in-client key management. Decide whether v1 ships encrypted or defers it.
- **Mobile scope.** Is mobile "photo/file backup" (one-way, the Immich-killer
  use case) a separate, simpler product than desktop two-way sync? Likely yes —
  they may not share an engine.
- **Trust / verification story.** See the deterministic simulation testing
  requirement below — this is not an open decision so much as a non-negotiable
  first-class deliverable. What remains open is the exact simulator boundary
  (how much of the real server is stubbed vs. run in-process).

## Verification: deterministic simulation testing (a first-class deliverable)

The edge-case state space above is combinatorial — it cannot be covered by
hand-written cases. The engine must be built **around** a fault-injecting
simulator, the way FoundationDB and Dropbox's Nucleus rewrite were. The harness,
not the engine, is the thing that earns the "never loses data" claim.

- **Simulate the world, not the code paths.** A virtual filesystem, a virtual
  network to the server (or the real server in-process), and a **controllable
  clock** — so every run is deterministic and reproducible from a seed.
- **Inject faults every run:** kill the process mid-write, drop/delay/reorder the
  network, corrupt a chunk, overflow the watcher (drop events), race two devices,
  reuse an inode, throw an illegal filename, revoke a share mid-sync.
- **Assert two invariants after every scenario, always:** (1) the engine
  **converges** to a consistent state, and (2) **no committed file version is
  ever lost** (the server's version history is the oracle).
- **Seed corpus + shrinking.** Property-based generation of operation sequences;
  on failure, shrink to a minimal reproducer and freeze it as a regression seed.

The acceptance bar for shipping any sync phase is a green simulation run across
the full fault matrix — not a passing set of example tests.

## Phasing (skeleton — refine when scoped)

- **Phase 0 — server gap-closing.** Ranged/resumable download, device
  registration, confirm the change-feed contract covers every case a two-way
  client needs (moves, grant changes, encrypted renames).
- **Phase 1 — desktop, plaintext, one folder, two-way.** The reconcile core +
  local state store + watcher. Correctness harness green.
- **Phase 2 — desktop encrypted-folder sync.** In-client key unwrap + crypto.
- **Phase 3 — selective sync + multi-device conflict hardening.**
- **Phase 4 — mobile backup** (likely one-way, its own thin engine).
- **Phase 5 — packaging, auto-update, signing per OS.**

## Docs to update when this lands

- `docs/drive.md` — add a "Sync clients" section (client contract, download
  protocol, device registration).
- `docs/mobile_apps.md` — mobile backup surface, if it reuses the app platform.
- `docs/file_signed_urls.md` — if ranged download changes the serve contract.

## Feasibility (one-paragraph verdict — expand later)

**Server-side: highly feasible, mostly done.** The upload protocol, change feed
with reset semantics, versioning safety net, signed-URL download, dedup, and the
client-custody crypto contract already exist and were designed with sync clients
in mind. The remaining server work is small and additive (ranged download,
device registration). **Client-side: feasible but genuinely hard, and the whole
risk lives here.** A reliable cross-platform sync engine is a serious native
build, and "reliable" is the exact bar Nextcloud misses — so the differentiator
is not features but a correctness harness that proves no-data-loss. The unique
upside only Joinery can claim: **syncing end-to-end-encrypted files that stay
usable**, because the crypto is already client-custody by design. Recommended
posture: build the engine core once, in an auditable language, correctness-first,
and let the encrypted-sync capability — impossible for Nextcloud — be the wedge.
