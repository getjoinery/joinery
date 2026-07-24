# Backup Key Custody & Disaster Recovery

**Status:** IMPLEMENTED 2026-07-24 — sealed-box escrow built (BackupKeyCustody + bke_backup_key_escrow + escrow_keypair.php), proven end-to-end by the F-2 disaster-recovery drill (node destroyed; key recovered from the B2 sealed blob + offline private key alone; byte-identical restore), and hardened with a prove-possession ceremony: the recovery public key is only honored after the operator unseals a challenge, so a mistyped key can never silently seal the fleet to an unopenable value. This document remains the problem statement and threat model; the implementation package is `specs/implemented/server_manager_1_0_hardening.md`.
**Area:** `plugins/server_manager` — backup/restore, `maintenance_scripts/sysadmin_tools/{backup,restore}_database.sh`
**Origin:** Investigating node 30 (Galactictribune) backup encryption on 2026-07-23.

---

## The plain-language problem

Every node encrypts its own backups with a secret that exists **only on that node**. The
encrypted archives are shipped offsite to Backblaze B2, but the key to open them never
leaves the machine. That means: **if a node is destroyed and its key was never copied
anywhere else, the offsite backups are unrecoverable ciphertext.** We would be holding
safe, intact, undecryptable backups.

This is a deliberate security tradeoff — keeping the key off the control plane is exactly
what makes a stolen B2 bucket or a dumped control-plane database survivable. But it turns
the per-node key into a **single point of failure for recovery**. We have traded a
confidentiality risk for an availability risk, and right now nothing mitigates the
availability side.

Separately, while confirming the above, we found that the **dashboard's restore path
cannot decrypt encrypted backups at all** — so even with the key present, restoring an
encrypted cloud backup through the UI currently fails. Details below.

---

## Current state (verified 2026-07-23)

### How encryption works
- Backups are encrypted on the node by `backup_database.sh` /
  `backup_project.sh`, producing `*.sql.gz.enc` / `*.tar.gz` archives.
- Cipher: **AES-256-CBC**, PBKDF2 key derivation, random salt:
  `openssl enc -aes-256-cbc -salt -pbkdf2 -pass pass:"$ENCRYPTION_KEY"`
  (`backup_database.sh:95`).
- Key source order (`backup_database.sh:241-256`):
  1. `BACKUP_ENCRYPTION_KEY` environment variable (not used by the platform today).
  2. `~/.joinery_backup_key` file on the node (600 perms).
  3. Interactive prompt (not reachable in job runs).
- The key is a random 32-byte base64 value, **auto-generated on first encrypted backup**
  if the file is absent (`JobCommandBuilder.php:508` / `:556`):
  `openssl rand -base64 32 > ~/.joinery_backup_key && chmod 600 ~/.joinery_backup_key`.
  The job logs `ENCRYPTION_KEY_GENERATED`; the value itself is never in job output.

### Where the key lives — and does not
- Only ever on the node, at `~/.joinery_backup_key` (home of the account the job runs as;
  e.g. `/root/.joinery_backup_key` for a root-SSH node like node 30).
- **No escrow anywhere.** Grep of the whole tree confirms nothing copies the key to the
  control plane, to B2, or to any other store. The control-plane database, the Server
  Manager UI, and the B2 bucket never contain it.
- B2 targets force encryption (mandatory, UI + server-side), so every node backing up to
  B2 has this dependency. Node 30's target is B2 (`joinery-backups-354`).

### The two defects

**Defect A — no key escrow → unrecoverable after box loss.**
The key is a single point of failure. Losing the node (and not having copied its key)
means its offsite B2 backups cannot be decrypted by any path.

**Defect B — the dashboard restore path never decrypts.**
`JobCommandBuilder::build_restore_database()` downloads the archive and pipes it straight
through gunzip with **no `openssl -d` step** (`:764-772`):
```
gunzip -t {file}  &&  ... gunzip -c {file} | psql ...
```
- `restore_database.sh` *does* handle `.enc` (detects the extension, decrypts with the
  key at `restore_database.sh:143-171`) — but `build_restore_database()` does not call it.
- The Backups-tab "Restore" button uses `build_restore_database()`
  (`views/admin/node_detail.php:137`). So restoring an **encrypted** cloud or local
  backup through the UI fails at `gunzip -t` — independent of Defect A.
- The **from-backup install** clone path (`JobCommandBuilder.php:1656`) also uses plain
  `gunzip -c | psql`, but it works because it transfers an **unencrypted** dump
  node-to-node over SCP (both boxes alive). This is a *clone* path, **not** a DR path —
  it requires the source node to be alive and never touches the encrypted B2 archive.

---

## Recovery scenarios today

| Scenario | Works? | Why |
|---|---|---|
| Restore on a still-alive node (key present) via `restore_database.sh` | Yes | Key on disk decrypts. |
| Restore an **encrypted** cloud backup via Backups-tab button | **No** | Defect B: builder never decrypts. |
| Clone to a fresh box via "From Backup" install | Yes | Transfers unencrypted dump node-to-node; source must be alive. |
| **Rebuild a lost node from its B2 backups** | **No** | Defect A: key was only on the lost node. Also Defect B on the UI path. |

The headline: **the one scenario backups exist for — the box is gone — is the one that
does not work.**

---

## Options for Defect A (key custody)

Lead consideration: preserve the property that compromising the control plane or the B2
bucket does **not** expose the key. Options that keep that property are preferred.

1. **Offsite escrow of each node's key (keeps the security property).**
   At provision time (and on `ENCRYPTION_KEY_GENERATED`), capture `~/.joinery_backup_key`
   into a deliberate offsite store — password manager, offline vault, or the platform's
   own Sealed Vault under a separately-custodied identity. Manual-ish but preserves
   "control-plane compromise ≠ key exposure." Pairs with a startup/inventory check that
   flags any node whose key is not escrowed.

2. **Operator-supplied `BACKUP_ENCRYPTION_KEY` per node (keeps the security property).**
   Instead of letting the node auto-generate a key only it knows, generate the key
   ourselves, store it safely, and inject it. Same net effect as (1), more deliberate;
   removes the auto-generate-and-forget footgun.

3. **Store the key in the control plane, SecretBox-encrypted (convenience, weaker).**
   One-click DR from the dashboard, but the control-plane DB now holds the key, protected
   only by the SecretBox master. This **reintroduces the exposure the current design
   avoids** unless the SecretBox master is custodied separately — otherwise it just moves
   the single point of failure. Band-aid unless master-key custody is genuinely separate.

**Leaning:** 1 or 2. They keep the guarantee that is the entire point of node-only
custody. 3 only if master-key custody is solved independently first.

Cross-reference: this is the same unresolved question as the parked **agent signing key
backup** location — a single "where do platform-critical secrets get escrowed" decision
could cover both.

## Options for Defect B (restore path can't decrypt)

This one is a straightforward correctness fix, largely independent of the custody policy:

- **Route the dashboard restore through `restore_database.sh`** (which already decrypts by
  extension) instead of the inline `gunzip | psql` in `build_restore_database()`; or
- **Add an `openssl -d` stage** to `build_restore_database()` (and the from-backup DB
  restore) when the filename ends in `.enc`, sourcing the key the same way the backup
  step does (`~/.joinery_backup_key` / `BACKUP_ENCRYPTION_KEY`).

Either way the restore path must obtain the key — which is why Defect B is only fully
solved once Defect A gives us a key to supply on a fresh box.

---

## Open decisions (for the "think about it" pass)

1. **Custody model:** escrow (1), operator-supplied (2), or control-plane SecretBox (3)?
   Tie to the agent-signing-key escrow decision.
2. **Where does escrow live** if 1/2 — offline, password manager, or Sealed Vault?
3. **Rotation:** if a key is rotated or a node rebuilt, how do old B2 archives (encrypted
   under the previous key) stay decryptable? Retain prior keys in escrow.
4. **Inventory/alerting:** should the dashboard surface "this node's backup key is not
   escrowed" the way it now surfaces broken monitoring? A backup you can't restore is as
   silent as monitoring that can't alert.
5. **Fix Defect B now or with the custody work?** It is a real UI bug today (encrypted
   cloud restore fails) and could be fixed ahead of the custody decision, but it is only
   *useful* for true DR once a key can be supplied to a fresh box.

---

## Evidence index (file:line)

- Encryption command: `maintenance_scripts/sysadmin_tools/backup_database.sh:95`
- Key source order: `backup_database.sh:241-256`
- Key auto-generation: `plugins/server_manager/includes/JobCommandBuilder.php:508`, `:556`
- No-decrypt cloud restore: `JobCommandBuilder::build_restore_database()` `:764-772`
- Restore button → builder: `views/admin/node_detail.php:137`
- `.enc` decrypt (only in the script): `restore_database.sh:143-171`
- From-backup clone restore (plain gunzip, node-to-node): `JobCommandBuilder.php:1656`
- Doc describing node-only custody: `plugins/server_manager/docs/overview.md` § Backup Encryption
