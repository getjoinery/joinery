# Drive Sync Soak & Chaos Environment — Specification

**Status:** **Phase A built and first run live 2026-08-06, committed as `ebe756f3`.**
The rig has now driven two real daemons against a real instance, and has already
paid for itself — see § What the first live runs found.

**Phase A build:** The `jd-soak` crate is a workspace member with 145 tests
green, the host topology is `{repo root}/sync/soak/`, and the bounded gate is
`tests/functional/sync/sync_soak_gate.sh`. The rig itself is live on the soak VPS
(two daemons under systemd, campaigns run by hand so far). **No cycle has yet
come back green** — see the findings section. Phases B and C are unbuilt. One decision changed in the building of it
(S10, below): the devices are host processes under one unix account each rather
than containers, because the daemon's control channel is loopback-bound by design
and a verifier outside a container cannot ask it anything.

Companion to `specs/drive_sync_clients.md` (the parent
spec). The parent spec's `jd-sim` harness is deterministic and simulated; this
environment is the opposite half of the verification story: the **real daemon**
on **real filesystems** against the **real server**, driven by realistic
application behavior and real faults, **for weeks of wall-clock time**, with
the same two invariants (convergence, no loss) checked continuously.

## Why this exists — what jd-sim cannot see

`jd-sim` proves the *logic* is right: every decision-matrix cell, every fault
the simulator can express, reproducible from a seed. It cannot see:

- **Real kernel behavior**: actual inotify coalescing/overflow under storm
  load, ext4-casefold and vfat semantics, real inode reuse, real rename
  atomicity, page-cache/fsync timing, real APFS on the mini.
- **The real server**: PHP/Postgres under sustained concurrent load, the real
  change-feed purge task, the real 24 h upload-token sweep, real signed-URL
  expiry, Apache restarts mid-chunk, real rate-limit buckets.
- **Real time**: backoff schedules interacting with poll intervals over hours,
  slow leaks (fd, spool files, journal rows), issues that only appear at the
  100k-entry / multi-day scale.
- **Real application write patterns**: the sim's workload generator emits
  abstract ops; Office, git, SQLite, browsers, and installers emit *storms
  with structure* (lock files, temp-rename dances, backdated mtimes,
  append-forever logs) that are exactly what has historically broken Dropbox,
  OneDrive, and Nextcloud clients.
- **MemFs blind spots**: Phase 2/3 already caught two cases where the
  simulator diverged from reality (commit guard stricter than the real fs;
  HFS+ folklore in the macOS personality). The soak box is the standing
  instrument for finding the rest.

**What this is not:** deterministic. There is no seed replay across a real
kernel and a real network. The substitute for replay is **forensics**: every
actor op, every injected fault, and every daemon decision is journaled with
timestamps, so a violation arrives with the evidence needed to reproduce it in
`jd-sim` as a new frozen scenario. The soak box finds bugs; the sim then owns
them as regressions.

---

# Architecture

```
soak host (Docker)
  ├── soak-server        a full Joinery instance (own DB, own domain), standard
  │                      container install — NOT dev.getjoinery.com
  ├── device-a (ext4)            ┐  real jd-daemon + jd-soak actor + chaos agent;
  ├── device-b (ext4 casefold)   │  sync root on a per-device loopback image so
  ├── device-c (vfat)            ┘  the volume can be yanked, filled, remounted
  ├── remote-actor       jd-soak driving the server API directly (the "web user")
  └── orchestrator       jd-soak orchestrate: segments, drills, verify, report
mac mini (scheduled)     a 4th device on real APFS, joining the same account
```

- **The soak server is a dedicated instance** built with the standard
  installer (`install.sh` container pattern). Weeks of hammering must not load
  dev's DB, and the verifier needs liberty to inspect the DB, crank settings
  (feed retention *down* to force real resets, version retention *up* so the
  oracle keeps everything, quota low/high as a fault), and wipe between
  campaigns. Running the installer's product here continuously is a free
  long-duration installer soak.
- **Device containers run the real, unmodified `jd-daemon`** — the shipping
  binary, its real SQLite store, real keychain fallback (0600 files, the
  honest headless custody path), linked via the real device-link ceremony
  (headless approve via a scripted browser session once per campaign).
- **Loopback sync roots**: each device's sync root is a mounted filesystem
  image (`mkfs.ext4`, `mkfs.ext4 -O casefold` + `chattr +F`, `mkfs.vfat`).
  This puts three genuinely different filesystem personalities on one Linux
  kernel — case-sensitivity, Linux-native casefolding, FAT's 2 s mtimes and
  unstable-across-remount inodes — and makes "unplug the drive" a real
  `umount`, not a simulation.
- **The remote actor** speaks `jd-proto` + `jd-crypto` directly against the
  API: uploads, edits, renames, moves, trash, restore, version restore —
  remote deltas without needing a second device, plus the only way to populate
  encrypted folders until Phase 4 syncs them.

## The `jd-soak` crate

One new workspace member, one binary, four roles. It reuses `jd-vfs`
(personality-aware name comparison for tree diffing), `jd-proto` (API access),
and `jd-crypto` (encrypted-lane content):

- **`jd-soak actor`** — runs one persona (below) against a directory, seeded
  per segment. Every completed write is journaled (see § The oracle) to an
  fsync'd JSONL *outside* the sync root, journal-then-act like the engine
  itself.
- **`jd-soak chaos`** — the per-device fault agent: kills, freezes, network
  faults (`tc netem` / `iptables` in the container netns), volume yank/fill,
  state-store abuse. Every injected fault is journaled with timestamps — a
  violation must be correlatable to the fault that provoked it.
- **`jd-soak verify`** — settle-and-check: quiesce actors, wait for every
  daemon to report converged, then assert the invariants *independently*
  (never trusting the daemon's green — see § Invariants).
- **`jd-soak orchestrate`** — the conductor: alternating **storm** segments
  (actors + chaos, default 45 min) and **settle** segments (verify, deadline
  15 min), rotating persona mixes and scheduled drills, writing the rolling
  report, freezing the world and capturing the forensics bundle on violation.
- **`jd-soak drill --target mock|live`** — scripted scenarios runnable against
  either `DriveApi` implementation (the trait already exists for exactly
  this). This is the parent spec's still-unbuilt `sync_live_gate.sh`
  mock-parity diff, absorbed here: the same scenario runs against `jd-sim`'s
  MockServer and against the soak server, and observable outcomes are diffed.

## The persona roster — problematic apps, faithfully

Each persona is a small state machine reproducing a documented real-world
write pattern (the class of behavior Dropbox's own engineering literature
catalogs as sync-hostile). Seeded per segment; mix rotated by the
orchestrator.

| Persona | Pattern it reproduces | What it hunts |
|---|---|---|
| `office` | `~$doc.docx` lock + write `tmpNNNN.tmp` + rename over original + delete lock; save bursts seconds apart | safe-save pairing (same path, new inode = edit), junk-list handling, quiet-period coalescing |
| `libreoffice` | `.~lock.doc#` siblings, temp-rename saves | ignore list, lock-file lifecycle |
| `editor` | vim swap `.f.swp`, backup `f~`, atomic write; emacs `#f#` autosave | rapid create/delete of siblings, rename storms |
| `photoshop` | write temp → **delete original** → rename temp into place | delete+create at same path must stay one entry (edit), not lose history |
| `sqlite-app` | continuous small transactions against `app.db` + `-wal` + `-shm` | never-quiet files, mid-write snapshots must never upload half a commit |
| `browser` | `.crdownload`/`.part` growing for minutes → rename; sometimes abandoned | growing-file stability check, orphaned temp cleanup |
| `git-user` | real git: clones, branch switches, rebases, `gc` pack rewrites | structured create/delete/rename storms, thousands of small files |
| `build-tool` | `npm`/`make`-shaped: deep trees, tens of thousands of files created then `rm -rf` | scale, watcher overflow, **mass-delete guard trips as routine events** |
| `archiver` | tar/unzip extraction with **backdated mtimes**, then move tree into place | mtime lies on real disks, bulk-move pairing |
| `log-appender` | append every few seconds for hours | starvation: one never-quiet file must not stall the tree, and must still ship during quiet windows |
| `camera-import` | bulk-copy 2k photos, then culling-app rename-all pass | bulk transfer, rename-batch pairing, dedup |
| `rsync-inplace` | mutates/grows a multi-GB file **in place** | mid-upload change → complete refused → clean requeue; ranged resume |
| `locker` | holds files open/locked for hours, writing while held | open-handle reads, partial-content hazards |
| `messy-human` | folder renames while children are being written; drag-move of big subtrees; "Copy of" dupes; delete then restore from OS trash; case-only renames; NFD/emoji/CJK/255-byte names | move detection under concurrency, name intelligence on real filesystems |
| `name-swapper` | A↔B swaps and rename cycles, occasionally cross-device | cycle-breaking on real filesystems |
| `racer` | coordinated same-path creates/edits/moves from two devices inside the quiet window | the known-failing seed family (216/225/229) reproduced against the real server |
| `hoarder` | grows the tree toward 100k entries / 50 GB (bounded, pruned) | cold-start walks, rescan cost, feed scale, multi-day leak detection |
| `remote-user` | the remote actor: server-side edits, renames, moves, trash/restore, version restore, share/permission churn | remote deltas, restore classification, `grant_changed` |
| `vault-user` | remote actor creating/editing **encrypted** files and folders via `jd-crypto` | today: devices must surface `EncryptedUnsupported` and never materialize ciphertext; from Phase 4: full encrypted-lane sync under every other persona's patterns |

Case-insensitive tortures run on the casefold and vfat devices; the verifier
diffs trees through `jd-vfs` personality comparison keys so a device is never
failed for obeying its own volume's rules.

## The chaos matrix — real faults on a schedule

Injected continuously during storm segments (Poisson-scheduled, journaled),
each mapped to the parent spec's edge-case list:

- **Process:** `kill -9` the daemon (mean ~20 min); `SIGSTOP` for minutes then
  `SIGCONT` (a hang that resumes); container restart (reboot semantics).
- **Network** (`tc netem` / `iptables` per device): added latency, loss,
  duplication, reorder; hard partition for minutes; connection cut
  mid-transfer; bandwidth crushed to KB/s during large uploads.
- **Server:** Apache graceful and hard restarts, php-fpm kill mid-request,
  Postgres restart, brief 503 windows — all while devices are mid-storm.
- **Disk:** fill the sync-root volume to ENOSPC (downloads must fail into
  `issues`, never half-materialize); fill the state-dir/spool volume
  (journal-write failures must fail safe); **yank the loopback mount**
  mid-storm (must hard-pause as root-gone, never read as mass delete),
  remount later and watch recovery.
- **Account/credential:** revoke the device from the web UI mid-sync
  (auth-dead surfaced, re-link recovers); password-change key sweep; from
  Phase 4, vault key rotation mid-storm (`re-link needed` on encrypted
  subtrees, plaintext sync uninterrupted).
- **Server-clock adjacent:** change-feed retention cranked low on the soak
  instance so **real feed resets** occur regularly (index-walk reconciliation
  under load); upload-token sweep accelerated so resumable uploads really do
  meet 404-restart.
- **State-store abuse** (scheduled drills, not random): delete the SQLite
  store (rebuild via index walk + hash pairing — asserting zero re-transfer
  via server byte counters); restore an hour-old copy of the store (stale
  cursor replay); bump `schema_version` forward (daemon must refuse to open,
  not guess).
- **Clock lies (best-effort, Phase B):** `libfaketime` jumps on the daemon
  only. Backdated mtimes are already covered for real by `archiver`.

**Mass-delete guard interplay is a feature, not a nuisance:** `build-tool`
and `messy-human` legitimately trip the `max(50, 25%)` guard. Headless, the
daemon refuses and logs; the orchestrator verifies the refusal is surfaced,
then issues the CLI proceed flag and re-settles. The guard therefore gets
exercised — trip, surface, operator-proceed — many times a day.

---

# Invariants and the oracle

## The actor journal is the source of truth

Each actor journals `{seq, persona, op, path, sha256, size, mtime, ts}` to an
fsync'd JSONL outside the sync root, recorded only after the filesystem
operation returned success. "Committed content" means exactly: a write whose
rename/close completed. The chaos agent journals every fault the same way.
The daemon's own JSONL logs are the third record. Between the three, any
anomaly has a timeline.

## Settle-segment assertions (every cycle, ~hourly)

1. **Convergence within deadline.** After actors quiesce, every daemon must
   reach converged within 15 min. A stall is a **failure**, not a wait — the
   parent spec's core promise is "never silently stop", so a silent stall is
   a first-class bug even with zero bytes lost. Guard-held deletes and
   surfaced issues are legitimate non-converged states only if visibly
   surfaced.
2. **Green is independently audited.** After the daemons report green, the
   verifier walks every device tree (through personality comparison keys) and
   the server (`drive_index`, both id spaces) and diffs them itself. A daemon
   that says green while trees differ is the worst bug class this rig exists
   to catch.
3. **No loss.** For every path, the last actor-committed version must be
   findable in at least one legitimate place: some device's disk, the server
   head (`content_sha256`), a version row (`drive_versions`), server trash,
   a local OS trash, or a materialized conflict copy. Additionally, every
   content the server ever acknowledged (`drive_upload_complete` success in
   the journal) must remain findable in head/versions/trash — the soak
   instance's version retention is set to keep-all so the server is a
   Mock-Server-grade oracle.
4. **Ciphertext never materializes.** No file under any sync root matches a
   known ciphertext hash, and (pre-Phase-4) every encrypted entity is
   surfaced as `EncryptedUnsupported` — never silently absent.
5. **Issues honesty.** Every entry not `synced`/`out_of_scope` has a
   corresponding surfaced issue with a reason; the tray-state reduction
   matches reality.
6. **Leak watch.** Daemon RSS, fd count, spool-dir residue, ops-table depth,
   and store size are recorded per settle; monotonic growth across a day
   flags a leak before it becomes an outage.

## On violation — freeze and capture

The orchestrator halts actors and chaos, then captures: all three journals,
every device's SQLite store and daemon logs, the relevant server rows
(`drive_changes` feed slice, file/blob/version rows for affected entities),
the affected loopback paths, and the last verify diff — bundled with a
generated timeline correlating the loss window to injected faults. The bundle
is the reproduction: the follow-up task is to encode the timeline as a frozen
`jd-sim` scenario (or a new simulator capability, when the sim could not have
expressed it — those are the most valuable finds).

Violations are logged (the report file plus the frozen bundle on disk) — no
active alerting; the report is checked periodically by hand (S9).

---

# Operation

- The orchestrator runs as a systemd unit on the soak host; campaigns run
  indefinitely. Segment cycle: 45 min storm → settle/verify (15 min deadline)
  → rotate personas/drills. Scheduled drills (state-store abuse, feed reset,
  re-link, mac mini join/leave) land between storm segments.
- `jd-soak report` renders the rolling JSONL into a daily summary: actor ops
  by persona, faults injected, convergence times (p50/p95/max), conflicts
  created, issues raised/cleared, guard trips, bytes moved vs deduped,
  retries, leak-watch trend — and the only number that ultimately matters:
  **invariant violations, which must read 0**.
- The mac mini joins on a schedule (respecting the mini's memory-budget house
  rule) as a real-APFS fourth device running the same actor/chaos roles at
  lower intensity, then unlinks — exercising link/unlink churn for free.
- **Release bar (owner-confirmed):** before Phase 6 ships
  public installers, one campaign of **7 consecutive days green** — all
  personas and drills enabled, ≥3 filesystem personalities, ≥1M actor ops,
  ≥100 daemon kills, zero invariant violations, and every convergence-
  deadline miss diagnosed to a surfaced (not silent) cause.

# Test estate

| File | Tier / env | Covers |
|---|---|---|
| `tests/functional/sync/sync_soak_gate.sh` | live / dev-only, needs `[rust, docker]` | brings up the compose topology, runs one bounded storm+settle cycle (~10 min) with a fixed persona mix and kill/partition chaos, asserts all six settle assertions, tears down. Proves the harness itself works; the multi-week campaign is operations, not a test tier. |
| `tests/functional/sync/sync_parity_gate.sh` | live / dev-only, needs `[rust]` | `jd-soak drill` scenario scripts run against MockServer and the soak/dev server; observable outcomes diffed. Discharges the parent spec's planned `sync_live_gate.sh`. |

The long campaign never runs from the test runner. Harness code follows the
house rule that the harness is itself tested: `jd-soak`'s persona state
machines, journal writer, and tree-differ get unit tests in-crate (rolled into
`sync_sim_gate.sh`'s cargo run), because a verifier bug that reports clean is
worse than no verifier.

# Phases

- **Phase A — the rig.** `jd-soak` crate (actor/verify/orchestrate + journal +
  tree-differ), compose topology with soak-server + two ext4 devices, device
  link scripted, core personas (`office`, `editor`, `photoshop`, `sqlite-app`,
  `browser`, `messy-human`, `name-swapper`, `remote-user`), kill + partition
  chaos, the six settle assertions, `sync_soak_gate.sh`. This already hunts
  the biggest game (data loss under kill/partition during app-storm writes).
- **Phase B — full adversary.** Remaining personas (`git-user`, `build-tool`,
  `archiver`, `log-appender`, `camera-import`, `rsync-inplace`, `locker`,
  `racer`, `hoarder`), casefold + vfat loopback devices, disk-pressure and
  volume-yank chaos, state-store drills, feed-reset + token-sweep
  acceleration, mass-delete drill loop, leak watch, `jd-soak drill` +
  `sync_parity_gate.sh`, first multi-day campaign.
- **Phase C — full fleet.** Mac mini scheduled device; `vault-user` lane
  flips from refuse-verification to full encrypted sync when Phase 4 lands;
  key-rotation and re-link chaos; shared-with-me personas when Phase 5 lands;
  the release-bar campaign gating Phase 6.

# Decisions (resolved by this spec)

- **S1 — Dedicated soak instance**, standard container install, never
  dev.getjoinery.com. The rig must be free to restart services, purge feeds,
  and wipe; dev must be free of weeks of synthetic load.
- **S2 — Real daemon binary, no test hooks.** Faults are injected from
  outside (signals, netns, mounts, server). A daemon compiled with soak hooks
  would soak a different program.
- **S3 — Journal-then-act oracle owned by the actors**, not the daemon; the
  verifier trusts only its own tree walks and the server's version history.
- **S4 — Loopback images per device** for filesystem personalities and
  yankable volumes; ext4 / ext4-casefold / vfat in the base fleet.
- **S5 — Forensics over replay.** No determinism pretense; every violation
  ships a timeline bundle, and the fix lands with a `jd-sim` regression.
- **S6 — Findings flow downhill to the sim.** The soak rig is the detector;
  `jd-sim` is where bugs become permanent, fast, deterministic regressions.

- **S7 — The rig lives on a small dedicated VPS** (owner-confirmed
  2026-08-06). Fully isolated from dev and from the scratch box's installer
  wipes; the mini reaches the soak instance over the network like any other
  device. Docker on the VPS, standard container install.
- **S8 — Release bar as stated** (owner-confirmed 2026-08-06): 7 consecutive
  green days, all personas/drills, ≥3 personalities, ≥1M actor ops, ≥100
  daemon kills, zero violations, every deadline miss diagnosed.
- **S9 — Log-only surfacing** (owner-confirmed 2026-08-06): violations and
  daily summaries land in the report file and forensics bundles on the VPS;
  checked periodically by hand. No email/push alerting.

- **S10 — Devices are host processes under one unix account each**, not
  containers (decided while building Phase A, 2026-08-06). This supersedes the
  compose topology sketched in § Architecture.

  The daemon binds its control channel to **loopback on a kernel-chosen port**,
  which is correct — binding it to every interface would put a client's sync
  controls on the network. It also means a daemon inside a container is
  unreachable from outside it, and the verifier must be able to ask a device when
  it has stopped working: assertion 1 *is* that question, and assertion 5 is
  checked against the answer.

  Three ways out, and only one is acceptable. Changing the daemon is refused by
  S2 — the program soaked has to be the program that ships. Proxying every status
  call through `docker exec` adds a moving part inside the one component whose
  trustworthiness the entire rig rests on. Running each daemon as an ordinary
  process under its own unix account costs nothing and gives back the thing
  containers were wanted for: a **per-device** network fault, through
  `iptables -m owner --uid-owner`, which cuts one daemon's traffic to the server
  and leaves its neighbours syncing. `Restart=always` on a systemd template unit
  supplies the supervisor that turns `kill -9` into reboot semantics.

  What this genuinely defers is the **volume** fault — yanking a sync root
  mid-storm — which wants a filesystem image rather than a container and lands
  with the loopback devices in Phase B. Nothing else in the fault matrix needs a
  container. The soak *server* is unaffected: it remains a standard container
  install on its own box, per S1.

- **S11 — Phase A links devices with the password login, not the browser
  ceremony** (decided while building Phase A, 2026-08-06). Both mint a real
  per-device session key through the real API; the only thing the ceremony adds
  is a vault key sealed to the device, and no Phase A persona touches an
  encrypted folder. The ceremony path gets built when the encrypted lane turns on
  in Phase C, which is the point at which the difference starts to matter. The
  ceremony itself is already live-verified separately (parent spec, Phase 0).

# What Phase A actually shipped

Against the Phase A list at the top of § Phases:

| Asked for | Built |
|---|---|
| `jd-soak` crate: actor / verify / orchestrate | `{repo root}/sync/jd-soak/`, workspace member, 130 tests. Also `chaos`, `report`, `init` and `provision` as their own subcommands. |
| journal | `journal.rs` — intent/commit/failed/fault/verdict/segment/sample, one JSONL per writer, merged by timestamp on read. Commits fsync'd; intents not (process death is the fault model, not power loss). |
| tree-differ | `tree.rs` — hashes every file, compares names through `jd-vfs` personality keys, and honours each device's own exclusions. |
| compose topology, soak-server + two ext4 devices | Superseded by S10. `{repo root}/sync/soak/setup-host.sh` builds the accounts, roots, units and fleet description; the server stays a standard container install elsewhere. |
| device link scripted | `jd-soak provision` (S11). |
| core personas | `office`, `editor`, `photoshop`, `sqlite-app`, `browser`, `messy-human`, `name-swapper`, and `remote-user` in `remote.rs`. |
| kill + partition chaos | Both, plus freeze (SIGSTOP/SIGCONT) and restart, on Poisson arrivals. Faults that could not be injected are journaled as `refused` so a run is never stronger than it looks. |
| the six settle assertions | All six in `verify.rs`, each with its own tests. |
| `sync_soak_gate.sh` | `tests/functional/sync/sync_soak_gate.sh` — live, dev-only, needs `[rust]`. **Not yet run against a real rig.** |

Two things worth knowing that were not in the plan:

- **`drive_versions` did not report what a version holds.** The export carried
  size and timestamps but no content hash, so a client could list a file's
  history and not tell which entry held the bytes it was looking for — and
  assertion 3 could not clear a superseded content. `content_sha256` added to the
  export; it is the head's identity in the same domain (plaintext bytes for a
  plaintext file, ciphertext for an encrypted one).
- **The harness is tested against a world with no client in it**, and is required
  to fail there. That test is in `jd-soak/tests/campaign.rs` and it is the one
  that matters: a verifier that called an empty world green would call a broken
  client green too.

# What the first live runs found

Two bounded campaigns against `drivetest.getjoinery.com`, 2026-08-06 — 6,425 and
8,551 actor operations, three injected faults each.

## One real defect in shipping code

**The control channel went dark exactly when the user had most to look at.**
`jd-platform`'s `MAX_BODY` was one constant serving two opposite purposes: the
largest request the daemon will read, and the largest answer a client will read
back. The `/status` answer grows with the number of open issues, so at 306 issues
it reached 70 KB, the client truncated it at 64 KB, the JSON failed to parse, and
`ask()` returned `None` — which every caller reports as **"the sync daemon did
not answer."** The daemon was running perfectly and syncing normally throughout.

The shape is what makes it serious. A client that goes blind in proportion to how
much is wrong is the precise failure the health model exists to prevent, and it
took `dismiss` down with it: the issue ids come from the call that had stopped
working, so there was no way back under the limit.

Fixed in three places, all mutation-verified:
- the two caps are separate constants, and a `const` assertion makes collapsing
  them back a compile error;
- `ask()` reads one byte past its cap so a truncated answer is refused as
  truncated rather than failing to parse into silence;
- the daemon caps the issue *list* at 50 (`MAX_REPORTED_ISSUES`) and reports
  `issues_total` separately, so the answer is bounded by construction and no
  shell ever understates the count.

Verified live: 405 open issues, answer down from 70 KB to 12 KB, CLI reporting
405.

## One server-configuration finding

**A fresh install with Drive enabled refuses every upload.** `drive_upload_init`
bails out when `drive_storage_bytes` or `drive_max_file_bytes` is 0, and both
default to 0 with no subscription tier. A device links successfully and then
fails every upload with *"That upload would exceed the storage quota"* — a quota
message about a quota nobody set. Worth deciding separately from this spec
whether that is a defaulting problem or a wording one.

Also: the stock `api_rate_limit_requests` of 1000/hour is right for a person and
far too low for a storm. Raised on the soak instance, which is what S1 exists to
allow. The client's behaviour under it was correct — surfaced blocker, retrying,
never silent.

## Four defects in the rig itself, all fixed

1. **The no-loss oracle was far too strong.** It demanded that *every* content an
   actor ever committed remain findable, and reported 2,966 violations in one
   segment. Nearly all were local writes replaced at the same path seconds later,
   before any client could upload them — a user overwriting their own file, which
   no sync client captures. Narrowed to what the spec actually says: the last
   committed version of every live path, plus every content **the server was
   observed to hold**, which is now remembered across settles.
2. **Issue honesty was measured against work in flight.** 248 pending uploads
   mid-drain read as 248 things the daemon was hiding. Now only terminal states
   (`unsyncable`, `pending_key`) require a surfaced reason; a draining queue is
   what a working client looks like.
3. **The verifier looked for the OS trash in the wrong place** — under
   `JOINERY_DRIVE_HOME` rather than the daemon account's unix home. It would have
   called a correctly-trashed file lost. `Device` now carries `unix_home`.
4. **A fault could outlive the storm that started it.** A 195-second partition
   drawn near the end of a 180-second segment held it open, and the settle then
   ran against a device the rig itself had cut off. Durations are trimmed to what
   is left of the segment.

Plus one scaling fix found by the rate limiter: the verifier walked every file's
version history every settle — one API call per file, which at 100k entries is
not viable. It now only searches version history when something is genuinely
unaccounted for, and stops as soon as it is found.

## Run 3 — the rig's first real finding about the client

Reset to a clean account and deliberately gentled: 120 s storm, 2 s actor pace,
900 s settle deadline. **869 actor operations, 3 faults, and four of the six
assertions passed** — the rig defects above are fixed, so what remains is the
client.

**A folder create that collides by name is retried forever.** `create_remote_folder`
comes back *"A folder with that name already exists here"*, the operation is
withdrawn, an issue is raised — and the same create is planned again on the next
pass, and the next. Measured during the settle with every actor stopped:

| | |
|---|---|
| `pending_ops` | **66, flat**, for the whole 900 s |
| open issues | 930 → 1004 in 45 s, ending at **1,340 and still climbing** |
| distinct causes | **3** |

Flat queue plus growing issues from three causes is a loop, not a backlog. The
device's own state store says why. It holds **two entries for one directory**:

| entity | name | status |
|---|---|---|
| `642` (real) | `Shared-office` | **unsyncable**, reason `unicode_clash:Shared-office` |
| `-3` (provisional) | `Shared-office` | `pending_upload`, 611 refused creates |
| `644` / `-2` | `Shared-name-swapper` | the same pair, 611 refused creates |
| `646` | `Shared-messy-human` | `synced` — this one adopted cleanly |

The server's folder is refused as clashing with a name **identical to its own**,
because the device's provisional twin holds first claim on it (`resolution_order`
ranks a provisional as materialized). The provisional can never be created — the
server refuses the duplicate name. The remote can never materialize — the
provisional owns the name. The deadlock is closed and self-sustaining, and the
subtree beneath is stranded, which is what the `audited-green` differences are
downstream of. The same self-clash appears on files: `Report 2.docx`,
`slot-3.dat`, `app.db-shm`.

**Root cause, established 2026-08-06 and reproduced deterministically.** A pass
reads the change feed, *then* walks the disk. A device that finds a directory
with no matching entry mints a provisional identity for it — correctly, because
at feed-read time no such folder existed on the server. In the window between
that feed read and its create landing, another device creates the same folder.
The create is refused and **the provisional survives**.

The winner's folder then arrives in the feed as a real entry, and the device is
holding two entries describing one directory. Name resolution treats them as
rival siblings, and `resolution_order` ranks a provisional as materialized — so
the provisional wins the name and the real folder is refused as clashing with
itself. Unsyncable means it never materializes, so it never occupies the path,
so the provisional is never superseded, so it re-plans its create every pass.

The engine has **no rule that a provisional entry and a remote entry at the same
placement are the same thing.** For folders they always are: the server enforces
one name per parent, so a local directory and a remote folder at the same path
cannot be different things.

Reproduced as `a_folder_that_lost_a_creation_race_still_converges` in
`jd-sim/tests/scenarios.rs`, carrying `#[ignore]` because it fails — it is an
open bug, not a regression guard. `cargo test -p jd-sim -- --ignored` runs it.
Getting there took four wrong attempts, all of which the engine survived; the
reason none of them reproduced is that an offline device never mints anything at
all (a pass that cannot read the feed aborts before the scan), so the window is
narrower than "two devices race" — it is bounded by one device's own pass.

**Fixed** in `jd-core`: `Store::merge_folder` folds a provisional folder into the
real one that turned out to be the same directory — children re-pointed, hash
cache moved, the doomed create dropped — and `pass::merge_duplicate_folders`
runs it before naming, which is what would otherwise turn the pair into rivals.
Doing it as a pass pre-step rather than in the create's error handler makes the
state unreachable however it arises, including from a crash, and avoids matching
on a server message string. Safe precisely for folders: the server permits one
name per parent, so a local directory and a remote folder at the same path
cannot be different things.

Verified twice. The reproduction above now passes, and deploying the build onto
the **still-deadlocked live devices** cleared them: provisional folders 2 → 0 on
device-a, the issue count frozen at 7,675 after climbing at ~1.6/s for fifteen
minutes, and the queue replaced by real work (42 downloads, 14 folder creates, 5
moves) instead of a create that could never succeed.

**Files are not the same case and are not a bug.** The remaining self-clashes are
two genuine server files sharing a name in one folder, which the server permits
and a filesystem cannot hold. Surfacing the loser is the designed behaviour, not
a deadlock.

Those four orderings are frozen too, as a **guard** rather than a reproduction:
they pin the paths that do work, so a fix cannot quietly break them.

Two things this is *not*. It is not silent: `issues-honest` passed with 1,905
surfaced across the fleet, so the client is loudly stuck rather than quietly
broken. And it is not a data-loss bug: `no-loss` passed.

**Second, smaller finding:** a raw database error reaches the user. One issue
summary reads `create_remote_folder was not carried out: Database INSERT failed
on table 'fol_folders' - SQLSTATE[23505]…`. A unique-constraint violation is the
server's business, not something to show a person.

Whether any of the content differences (`Report 1.docx differs: device … vs
server …`) is an independent bug cannot be told until the loop is fixed — a
client that never finishes will differ from the server for reasons that say
nothing about its correctness.

**A fifth finding, in the rig again, and it wasted a settle:** the actors run as
root while each daemon runs as its own account, so directories an actor created
came out mode 755 and the daemon could not materialize anything inside them.
Downloads failed `permission denied`, whole subtrees never arrived, and the tree
diff reported it as the device missing what the server had — indistinguishable
from a client bug until somebody read the error text. Actor-created directories
are now `2775`, the setgid bit carrying the group all the way down.

**A third finding, and the reason the first survived this long:** the mock server
did not enforce folder-name uniqueness, while the real one always has
(`DriveHelper::folder_name_taken`). The simulator has therefore never once
exercised the engine against that refusal — it was answering a question the real
server never asks. Fixed. Two existing tests that proved "a retry without a key
does the work twice" by creating a duplicate folder now use distinct names, since
the duplicate would legitimately be refused.

Evidence kept off the rig at `/root/soak-evidence/run3-folder-retry-loop` on the
soak VPS, alongside `run2-violation`. **The devices are still deadlocked**, so a
candidate fix can be tested against a live reproduction even without a
deterministic one.

## Run 5 — a silent stall, and the reason nothing could see it

Run 5 ran on the merged-folder fix with a fresh account and a fresh rig. The
deadlock was gone. What it left behind was worse, and quieter: **thirty-two
entries on one device sat in `pending_upload` with no operation queued and
nothing raised**, frozen for the whole fifteen-minute settle while the device
reported itself busy rather than broken. Every one of them named a parent folder
that was **not in the store at all**.

**How they got there.** A folder the server has never seen is a *provisional*
entry. When the directory it stands for is removed from the disk before its
create lands, the pass correctly concludes the entry is nothing at all and
deletes it — but deleted only the folder, leaving the entries for the files
inside it naming a parent that had gone.

**Why nothing noticed.** A pass resolves an entry to a path by walking parents
up to the root, so an entry with no parent has no path and is skipped. Worse,
the pass enumerated entries by *walking down from the root*, which cannot reach
an entry whose parent is missing — the orphans were not merely skipped, they
were invisible to the only thing that could have found them. No work planned, no
issue raised, no state anybody could observe from outside.

**Fixed in three parts**, because one of them alone would leave the hole:

1. `Store::delete_provisional_subtree` takes the whole subtree, so removing a
   never-created folder cannot strand what was inside it.
2. The pass enumerates from the table rather than walking the tree, so an
   orphan is at least *seen*. Callers either resolve paths themselves or sort by
   depth afterwards, so nothing depended on the walk's ordering.
3. An entry that resolves to no path is cleaned up rather than skipped, so
   however the state arises it does not persist.

`assert_no_entry_is_stranded` now runs after **every** sim scenario, which turns
the whole existing suite into a net for this class. It is checked there rather
than only in a dedicated test because the state is cheap to detect and
impossible to see from outside — the combination that makes an invariant worth
having.

Run 5 also produced the **first `no-loss` violation on a converged-ish run**: one
committed file (`Projects/Copy of doc-9.txt`) was on no device, not on the
server, and not in a trash. See the oracle finding below — most violations of
this shape turned out to be the rig's own accounting.

## The oracle was losing track of files when their folders moved

Runs 8 and 9 reported five files as lost, and the hashes really were on no disk,
in no trash and not on the server. The claims were wrong, not the client.

`last_committed` keys a claim by full path, and it handled a file being renamed
or deleted — but not a **folder**. The messy-human persona shuffles directories
constantly, so a file written at `Projects (6) (10) (11)/Sub 12/Copy of doc-9.txt`
was carried to `Projects (6) (10) (11) (19) (21)/Sub 12 (18)/Copy of doc-9.txt`
by two folder renames, and then overwritten there by the same persona. That
overwrite is explicitly *not* loss — the oracle says so, and refuses to demand
every intermediate save — but the check is "replaced at the same path", and the
path had changed underneath it. The stale claim survived and demanded content
the user had themselves replaced.

Fixed: a claim now follows its folder. A `rename` is paired with the
`rename_into` that follows it and every claim beneath the old prefix is re-keyed;
a deleted folder takes the claims inside it. Prefixes are matched with a
trailing `/`, so `Sub 1` never drags `Sub 12` along.

Replaying the preserved journals through the fix: **run 9's single violation
disappears, and three of run 8's four remain.** Run 10, the first campaign with
the fix, **passed `no-loss`** — the first time that invariant has come back
clean.

**What the fix still cannot express.** Claims are keyed by path and nothing
else, but each device has its own tree and syncing is what eventually makes them
agree. Both devices rename their own copy of a shared folder during a storm, and
each rename re-keys every claim beneath it — including claims for files the
other device wrote. A rename that arrives on the second device *by sync* is
journaled by no actor at all, so a claim can be stranded that way too. The
consequence is bounded: it can only move a claim to a path nothing is at, which
shows as a reported loss. It cannot hide a real one, because a claim is checked
by searching for its **content** everywhere, never by its path. Keying claims by
content lineage rather than path is the real answer if this keeps costing.

**The three from run 8 are still open**, and they are not obviously the above:
`Projects/Sub 9/Sub 13/doc-15.txt` was written once (seq 44), never renamed,
never deleted by any actor, and no `remove_dir` occurs anywhere in that run —
yet its content was on no disk, in no trash and not on the server. A file
removed locally that no actor removed is the client, or it is a claim stranded
by a sync-driven rename. Deciding which needs the device trees, which run 8's
bundle has and the later resets do not overwrite.

The lesson is the one the rig was built on and nearly failed: **an oracle that
is wrong in the direction of alarm is not harmless.** It cost a full session
chasing client bugs that were not there, and it would have cost more if the
paths had looked less peculiar.

## Two more findings, both fixed

**A folder create returned a raw `SQLSTATE[23505]`.** `folder_name_taken()` runs
before the insert, so two concurrent creates both pass it and the partial unique
index refuses the loser — with a Postgres constraint name, verbatim, to the
client. A sync client retries an unexplained database failure forever; it knows
what to do with a name that is taken. Every folder create, rename, move and
restore now saves through `DriveHelper::save_folder_unless_name_taken()`, which
on a uniqueness refusal asks the same question again and answers as it would
have a moment earlier. The client also refuses to show any message carrying a
`SQLSTATE` to a user, keeping the raw text in the issue's detail — it meets
servers of every version.

**Deleting a file while it was uploading raised an item needing attention.**
Every operation that stopped raised an issue, whether or not anything was wrong.
Withdrawal is now two outcomes: *withdrawn* (the server refused it in a way
another attempt will not change — raised, because a person must decide) and
*overtaken* (the premise stopped holding — dropped in silence, because the next
pass plans afresh and nothing about it is anybody's problem). Where an overtaken
operation ran into something worth knowing about, that thing reports itself: a
scenario pins the conflict wording so this cannot quietly become silence.

## The first long campaign — six cycles, and three things only length reveals

**A memory leak, and the assertion built for it finally ran.** Leak-watch needs
six settles to call a trend and had never once had them: every campaign before
this died in its first or second. Device-a's resident set rose at every single
settle — 16852 → 17904 → 17936 → 19900 → 19912 → 20140 KB. Monotonic across
six samples is the shape the assertion exists to catch.

**The client degrades over a campaign rather than reaching a steady state.**
Items needing attention per settle: 26 → 56 → 95 → 141 → 165 → 189. A single
cycle cannot distinguish "this client has a bug" from "this client accumulates
one"; six can, and the answer is that it accumulates.

**One file lost identically in three consecutive segments**
(`Sub 5 (13)/33-文件.txt`, sha `a7bacb708f44`), which makes it the first loss
stable enough to chase directly rather than statistically.

### A rig-configuration false alarm, fixed

Segments 2 and 3 reported *"12 (then 19) contents the server had taken have
disappeared from it"* — the oracle's historical half, and much more alarming
than a local loss because it accuses the server of dropping data it had
accepted.

It was the rig. The soak tier set `drive_versioning_depth = 50`, so the server
prunes old versions exactly as designed, while the oracle holds it to *"once it
has taken a content it still has it."* Six cycles of the messy-human persona
overwriting the same files pushed them past fifty versions and the server did
the right thing. Depth is now effectively unlimited on soak accounts, because
the premise has to be made true rather than the oracle taught about pruning —
the server is the oracle, and an oracle with an exception in it is not one.

### The convergence assertion was too weak, and is now stronger

Chasing why `audited-green` failed in every run while `convergence` passed:
`is_settled()` asked the daemon's op queue and the indicator, and nothing else.
An entry can sit in `pending_upload` with **no operation queued for it** — the
silent stall this whole rig exists to find — and that read as settled. Run 16
passed convergence holding eleven pending downloads and eight pending uploads;
only the tree comparison noticed.

Assertion 5 does not cover it either, and correctly so: it holds the daemon to
explaining `unsyncable` and `pending_key`, on the reasoning that a draining
queue should not demand an alert per file. So the gap is closed in
`is_settled()` instead, which now also requires that no entry is in flight —
`synced` and `out_of_scope` are agreement, `unsyncable` and `pending_key` are
legitimate resting places, everything else is unfinished work.

**This makes earlier convergence passes untrustworthy in hindsight**, runs 15
through 17 included. It is the right direction: the assertion was calling
something settled that was not.

## What has not been demonstrated yet

**A green cycle.** Runs 1 and 2 failed for hardware reasons (the storm
out-produced what the settle could drain on one vCPU); run 3 was gentled until
that stopped being true and exposed the folder-create deadlock; run 5 exposed
the stall underneath that. Each run has reached further than the last, which is
the rig working — but nothing has yet come back clean.

Also still outstanding: a multi-cycle campaign (leak-watch needs six settles and
no campaign has passed one), and `sync_soak_gate.sh` run as an actual gate
rather than by hand.

# Docs to update when phases land

- `docs/drive_sync.md` — "Soak environment" section: topology, personas,
  invariants, how to read a report, how to run a drill (current-state voice).
- `docs/testing.md` — the two new gates in the estate table.
