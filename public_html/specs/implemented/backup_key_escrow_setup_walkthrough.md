# Backup Key Recovery — Guided Setup

**Status:** IMPLEMENTED 2026-07-24 — walkthrough panel, state model, refusal-free surfaces, dashboard row, tests and
docs all built; steps 1→3 walked in the browser on dev with a throwaway keypair (then cleared, key destroyed), safe
57/57 and db 165/165 green. Plugin 1.9.8.
**Area:** `plugins/server_manager` (Backup Targets page, node detail Backups tab, dashboard, `BackupKeyCustody`)
**Design authority for the custody model:** `specs/implemented/backup_key_custody_and_recovery.md` (the why) and
`specs/implemented/server_manager_1_0_hardening.md` § Phase 2 (the mechanics). This document changes **no crypto and
no custody rules** — it only makes the existing setup discoverable, self-explaining, and impossible to half-finish.

---

## The problem

Backup-key escrow is built and correct, but it is invisible until it blocks you. Today an operator meets it like this:

- The Backup Targets page shows a verify card **only after** a public key has been pasted into a setting — and nothing
  on any page tells you to paste one, or where the key comes from.
- Nothing anywhere explains what the recovery key *is* or why the platform is asking for it.
- A node's Backups tab offers **Run Database Backup** / **Run Project Backup** buttons that cannot succeed: encryption
  is forced for a cloud target, so `ensure_backup_key_if_encrypting()` throws and the operator gets an error message
  where they expected a job.

So the current state of this deployment is the expected state, not an anomaly: escrow public key empty, zero escrow
rows, and encrypted backups silently unavailable on the two nodes that have a B2 target.

The fix is a **detected, walked-through setup**: the platform notices escrow is not ready, explains in plain terms what
the operator is about to do and why, walks the steps in order, and never offers an action that is going to fail.

---

## What the operator is actually doing (the words the UI should use)

Each node encrypts its own backups with a secret that lives only on that node. That is deliberate — a stolen backup
bucket or a dumped control-plane database is worthless without it. The cost is that if the node burns down and its key
was nowhere else, its offsite backups are permanently unopenable ciphertext.

So the operator creates **one recovery key that only they hold**. The control plane gets the public half: it can lock a
copy of every node's backup key into a sealed envelope, but it can never open one. The private half lives in the
operator's password manager and never touches a server. Sealed envelopes are stored both in the control-plane database
and next to the backups in the bucket, so recovery works even if the control plane itself is the casualty.

The one-time possession check exists because sealing is unverifiable from the outside: a mistyped public key seals
perfectly, reports success, and produces envelopes nobody can ever open — discovered at the worst possible moment. So
the platform seals a challenge to the pasted key and requires the operator to open it with the offline private key
before that key is honored.

**Copy rule for the build:** the UI carries the short version of the above — one or two sentences per step, in these
terms (what it does for the operator), never a paragraph of theory and never crypto jargon as the subject of a
sentence. Long-form background belongs in `plugins/server_manager/docs/overview.md`, linked from the panel.

---

## Setup state model (single source of truth)

New: `BackupKeyCustody::setup_state(): array` — the only place any surface decides what to show.

| State | Detected by | Meaning |
|---|---|---|
| `unconfigured` | `server_manager_escrow_public_key` empty | No recovery key exists yet. Step 1. |
| `invalid` | setting non-empty but not a 32-byte base64 box public key | Something was pasted wrong. Step 1, with the parse error shown. |
| `unproven` | parses, `needs_possession_proof()` true | Key pasted, possession not demonstrated. Step 2. |
| `nodes_pending` | proven, and ≥1 node with a backup target has no escrow row (or a fingerprint matching no row) | Step 3. |
| `ready` | proven, every targeted node escrowed | Done panel. |

The returned array also carries, for rendering without a second round of queries: proven-key fingerprint (short form),
the pending-node list (id, name, reason: `never_escrowed` | `regenerated`), escrowed-node count, and the agent
signing-key escrow status (`BackupKeyCustody::agent_signing_key_unescrowed()`, wrapped so a failure there degrades to
"unknown" rather than throwing the panel away).

`needs_possession_proof()`, `is_escrow_configured()`, and `NodeMonitorHealth::backup_escrow_problems()` keep their
current behavior and semantics; `setup_state()` composes them.

---

## The walkthrough panel

**Placement (D1, resolved):** the panel sits at the top of `/admin/server_manager/targets` (anchor
`#backup-key-setup`), replacing the bare "Verify the backup recovery key" card. That page is the fleet-level backup
configuration surface and already owned the verify step; a separate route would add a nav entry for something you
touch once. A dedicated `/admin/server_manager/backup_keys` page is the alternative if the panel ever grows into
ongoing key-rotation management.

The panel is a stepper: completed steps collapse to a single green line, the current step is expanded, later steps are
listed but inert. No step is skippable, because each one's input depends on the previous one's output.

### Step 1 — Create your recovery key

- One-line what/why (see copy rule), then the exact command in a copy-able block:
  `php maintenance_scripts/sysadmin_tools/escrow_keypair.php generate --private-out ~/recovery.key`
- Explicit instruction that the private key goes into the password manager and the local file is deleted; explicit
  statement that the platform will never ask for the private key and cannot store it.
- FormWriter form: paste the printed public key → `save_escrow_public_key` action.
- **The panel never offers to generate the keypair server-side.** A private key that exists on the control plane
  defeats the entire model; there is no convenience toggle for this.

### Step 2 — Prove you hold the private key

- One-line what/why: this proves the pasted key produces envelopes your key can actually open.
- Challenge blob (regenerated per page load; sealed boxes are randomized, only the content matters) + the command:
  `php escrow_keypair.php unseal --private ~/recovery.key --in challenge.txt`
- Paste the output → existing `verify_escrow_key` action, unchanged.
- Mismatch keeps the current error copy and returns to this step.
- A "Use a different public key" control here clears the setting back to Step 1 (no escrow rows exist yet at this
  point, so nothing is orphaned by doing so).

### Step 3 — Escrow the keys of nodes that already have one

- Lists every node with a backup target that has no matching escrow row, each with its reason in plain terms
  ("has a backup key that only exists on that node" vs "key was replaced on the node and the new one isn't escrowed").
- Per-node **Escrow key** button (existing `escrow_backup_key` action) plus **Escrow all pending** which loops the same
  `BackupKeyCustody::ensureNodeKey()` path per node, reporting per-node success/failure rather than aborting the batch
  on the first SSH failure. Failures stay listed with their error and can be retried.
- Copy states plainly that this keeps each node's existing key (so backups already in the bucket stay readable) and
  never regenerates it.

### Step 4 — Done

Green summary, and the only place that states the standing facts:

- Recovery key verified, short fingerprint shown so it can be matched against the password-manager entry.
- N nodes escrowed; nodes with no cloud target are listed as not applicable (they keep local-only backups).
- Agent signing key: escrowed, or "escrows automatically on the next published release" when not.
- One-line reminder of the recovery path, linking to the DR runbook section in the plugin docs.

---

## Never offer an action that will fail

Node detail → Backups tab (`includes/node_detail_tabs/backups.php`):

- **Node with a cloud target** (encryption forced) while `setup_state()` is not `ready` for that node: the **Run
  Backup** box is replaced by a warning box — encrypted backups are unavailable until backup key recovery is set up,
  one sentence on why (a backup nobody can decrypt is not a backup), and a button linking to the walkthrough panel at
  the exact step that is outstanding. The Run Backup forms are not rendered at all.
- **Node with no cloud target** while escrow is not ready: the backup forms stay, but the **Encrypt backup** checkbox
  renders unchecked and disabled with a one-line note and the same link. Unencrypted local backups keep working.
- The existing per-node escrow status alerts (`Backup key not escrowed` / `Backup key escrowed`) stay, with the
  not-escrowed variant linking to the walkthrough instead of naming a raw setting.

`ensure_backup_key_if_encrypting()` keeps throwing — the server-side refusal is the real guarantee and stays exactly as
it is. This section only stops the UI from presenting an impossible action.

Dashboard (`views/admin/index.php`): the escrow alert gains a control-plane-level row when `setup_state()` is
`unconfigured` / `invalid` / `unproven` — "Backup key recovery is not set up — encrypted backups are unavailable" —
linking to the walkthrough. This is the case that currently surfaces nowhere: a fleet with no cloud targets yet
produces zero escrow problems and no prompt, so the operator only discovers the requirement when a backup refuses to
run.

---

## Setting write path

New `BackupKeyCustody::set_escrow_public_key(string $b64): void`:

- Validates by parsing (32-byte base64 box public key) and throws `BackupKeyCustodyException` with the operator-facing
  message on failure — nothing is written unless it parses.
- Upserts `server_manager_escrow_public_key` in `stg_settings` (same shape as the existing `write_proof_setting()`
  upsert, group `server_manager`).
- **Refuses to overwrite a proven key that already has escrow rows sealed to it** unless called with an explicit
  rotation flag. Replacing the key is a real operation (old blobs stay openable only with the old private key; new
  blobs need the new one) and must not happen by pasting into a box. Rotation itself is out of scope here — the
  refusal message says to use the documented rotation procedure.
- Writing a new key clears the proof marker, so the possession check runs again for the new value.

The page action is permission 10, POST, and CSRF-validated through `SmAdminCsrf` like every other action on that page.
The pasted public key is not a secret, but the challenge/proof strings are never logged.

---

## Tests

Extend `plugins/server_manager/tests/backup_key_escrow_test.php` (db tier) — no new file:

- `setup_state()` returns each of the five states from a constructed fixture (empty setting, garbage setting, pasted
  but unproven, proven with a targeted node lacking a row, proven with all rows present), restoring the original
  setting values in teardown.
- `set_escrow_public_key()` rejects non-base64, wrong-length, and empty input without writing; accepts a valid key;
  clears the proof marker on write; refuses an unflagged overwrite once rows exist.
- Bulk escrow reports per-node outcomes and does not abort the remaining nodes when one fails (simulated failure via
  an unreachable node fixture).

Acceptance requires the safe and db tiers green (`php tests/run.php safe`, `php tests/run.php db`).

---

## Docs (same phase as the behavior)

`plugins/server_manager/docs/overview.md` § **Backup Encryption** is currently stale and contradicts the shipped code —
it describes node-side auto-generation on first backup and tells the reader to `cat ~/.joinery_backup_key` over SSH.
Rewrite it to describe current state only: sealed-box custody, the recovery keypair and where its private half lives,
the possession check, per-node escrow rows and fingerprint verification, what the walkthrough does, and the disaster
recovery procedure including the control-plane-dead variant (pull the sealed blob from the bucket, unseal with
`escrow_keypair.php`, restore). No migration narration.

---

## Acceptance

1. A deployment with no recovery key configured shows the walkthrough at Step 1 on the Backup Targets page and a
   control-plane row in the dashboard escrow alert.
2. Walking Steps 1 → 4 in the browser, with a keypair generated for the walkthrough, ends in the done panel with the
   two B2 nodes (Empoweredhealthtn, Galactictribune) escrowed against their existing keys.
3. Before setup, a B2 node's Backups tab shows the warning box and no Run Backup forms; a local-only node shows the
   forms with encryption disabled and explained. After setup, both are normal and an encrypted backup job runs.
4. Pasting a malformed public key is refused with a readable message and writes nothing.
5. safe + db tiers green; plugin version and every touched file's `@version` bumped.
