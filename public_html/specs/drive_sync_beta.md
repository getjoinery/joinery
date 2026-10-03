# Drive Sync Beta on Real Data — Specification

**Status: proposed 2026-10-02, not started. For owner review in a few days.**

**Owner input 2026-10-02, decisions deferred until the soak results are in:**
the owner's own machine is **Windows**, and they would rather skip the copy
and test account and go straight to their real files on their own instance
(jeremytunnell). The plan below was written for Linux and Mac and needs
revising. The revision proposed, not yet agreed: (1) port the soak to the
Windows 11 VM on the Mac mini and run it for days (Windows has unit tests on
real NTFS and one short live pairing, but no soak hours); (2) W3, so
jeremytunnell runs the server code the soak tested; (3) the owner's real
folder, after a full backup copy kept outside the sync folder, one folder
first, never inside OneDrive. W4's copy and test account would then go.
Open: is the PC x64 or ARM64 (the VM is ARM64), and which folder first.

Companion to `drive_sync_clients.md` (the client) and `drive_sync_soak.md` (the
rig whose release bar this beta deliberately sits below).

## What the user gets

The owner, and perhaps one tester, can run the sync client on their own files
the day the soak's seven-day clock reads green — with the one path the soak
never runs (a first sync of a folder that already holds thousands of files)
already tried on data shaped like theirs, and nothing done to the originals.

## Why this exists

The goal set on 2026-10-02 is "beta versions running on real data". The only
written bar is the soak's S8 (7 consecutive clean days, three filesystem
personalities, 1M actor ops, 100 kills, zero violations), and it gates
**public installers**. A narrow beta can start below it, on these terms:

- the owner and at most one tester, on files backed up somewhere else;
- Linux and macOS;
- plain folders only — vaults (Fortress) wait for the full S8 bar;
- one drive per OS account (two drives under one account share one stored
  credential: B15 in `drive_sync_reset.md`).

The gate agreed that day, and where it stands:

| | | |
|---|---|---|
| A1 | 7 clean days on the rig, on the build that ships | Clock running from run 1570 on client `38e16c3a` (commit `3c0e4848`); earliest 2026-10-09. `python3 /root/soak_clock.py` on the soak VPS. |
| A2 | B1 fixed: a sync root on another volume never downloaded | Fixed and committed (`3c0e4848`). |
| A3 | B2-B5 re-checked against today's engine | Done: B3 and B4 closed, B2 narrowed to a recorded residual, B5 open (vaults only). |
| A4 | Days of clean runs on a real Mac (APFS) | Mac mini campaign running (`~/soak-mac`, own ledger and account pool); its first run found and fixed B-mac1 (macOS trash). |

The soak cannot answer two questions, and this spec is about them.

1. **How does a person install and link the client?** Installers, signing,
   notarization and auto-update are Phase 6 of `drive_sync_clients.md`, unbuilt.
   The client already has every command a beta needs (`login`, `daemon`,
   `autostart`, `status`, `issues`, `pause`, `resume`, `unlink`); nothing
   packages it or says how to use it.
2. **What happens on the first sync of a real folder?** Every soak run starts
   from an empty account and an empty disk and grows a tree under storm. A
   beta user starts with thousands of existing files and links them. That cold
   start (the full walk, the first upload of everything, a second device's
   first download of everything) has never run at the size or shape of real
   data.

## Work

### W1 — Beta builds

- `joinery-drive` (daemon + CLI) and the tray (`jd-shell`) for Linux x86_64 and
  macOS arm64, built from the commit the clock is measuring. The clock covers
  a build; a beta binary from any other commit is not what was tested.
- macOS builds are ad-hoc signed and not notarized. The first launch needs one
  Gatekeeper override; the guide (W2) gives the exact step.
- A `SHA256SUMS` beside the binaries, and the commit and client hash recorded
  in the guide, so a tester's report names the build it came from.
- Where they live: a private download (an Artifact or a file on the owner's
  instance), not a public page.

### W2 — The beta guide

One page, written for the tester, not the developer:

- install the binary; the Gatekeeper step on macOS;
- `joinery-drive login https://<site>` and approving the device in the
  browser (the device-link ceremony, `drive_sync_clients.md` §I.5);
- choosing the sync folder; `joinery-drive autostart on`;
- reading `status` and `issues`; `pause`, `resume`, `unlink`;
- where the logs and the state store live on each OS;
- what to send when something looks wrong (status, issues, the log, the
  build hash), and the one rule: **never delete the backup of the originals
  during the beta**.

### W3 — Server parity

The beta talks to the owner's own instance, not the soak instance. The soak's
seven days validate the client **and the server it ran against**
(`drivetest.getjoinery.com`). Before the beta:

- compare the Drive sync server code on the target instance with what the
  soak instance serves (the API surface `drive_sync_clients.md` Part I names,
  idempotency replay of refusals, device link);
- list any gap and close it by upgrading the target instance, never by
  pointing the beta at an older server and hoping.

### W4 — First sync of real data, on a copy

The part the soak cannot do.

- **The data:** a copy of a real folder the owner picks — a few thousand
  files, a mix of photos, office documents and things edited often. The
  originals are never linked, never touched.
- **The account:** a separate test account (on the owner's instance, or on
  the soak instance if W3 is not done yet), so a failure costs nothing real.
- **The run:**
  1. record a manifest of the copy (path, size, SHA-256) before linking;
  2. link device 1 to the copy; let the first sync finish; record wall time,
     CPU, peak memory, and anything in `issues`;
  3. link device 2 (the Mac mini, or a second Linux box) to an empty folder;
     let it download everything; record the same;
  4. compare device 1, device 2 and the server against the manifest: every
     file present, every byte equal, nothing extra;
  5. use device 1 normally for a few days (edits, renames, a moved folder,
     a deleted and restored file); compare again.
- **Pass:** every comparison exact; no open issue that is not explained by
  something the user did; no stuck op after a settle; CPU and memory that a
  laptop owner would not notice.

## Acceptance

The beta starts when all of these hold:

- A1: the soak clock reads 7 days on the build W1 packages, no violation;
- A4: the Mac campaign has days of clean runs on the same client code;
- W1-W3 done;
- W4 passed on the copy.

Any engine defect found here goes through the usual path (cause traced,
reviewed approach-before-patch, pinned, swept) and its fix restarts the
clock. The beta waits for the clock; it never starts on a build the clock
did not measure.

## Out of scope

- Public installers, notarization, auto-update, a download page (Phase 6).
- Vaults / Fortress folders (full S8 bar first).
- More than one tester, or anyone without their own backup.
- Two drives under one OS account (B15).

## Open questions for the owner

- **Q1** Which folder is copied for W4, and roughly how large?
- **Q2** Where does the W4 test account live: the owner's own instance (needs
  W3 first) or the soak instance (can start now)?
- **Q3** Second device for W4 step 3: the Mac mini, or another machine?
- **Q4** Is there a tester besides the owner, and on which OS?
