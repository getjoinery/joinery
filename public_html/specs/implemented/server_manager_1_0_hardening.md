# Server Manager 1.0 Hardening — Executor Package

**Status:** IMPLEMENTED 2026-07-24 — all 8 phases built, the F-2 DR-drill final acceptance gate passed on real infrastructure, and a full pre-commit adversarial review fix pack applied on top (retention heredoc, CSRF on the remaining save handlers, sweep idempotency, Cloudflare routing probe, escrow prove-possession, restore staging hardening, placeholder-only credentials, unified port allocator). Plugin 1.9.5.
**Version:** 2.0 (v1 was the audit report; this version is the build package)
**Scope:** `plugins/server_manager` + `maintenance_scripts/sysadmin_tools/{backup,restore,copy}_*.sh`
**Design authority for the custody problem:** `specs/backup_key_custody_and_recovery.md` — the *why* and the threat model. This document is the *how* and is authoritative on mechanics.

**Resolved decisions (owner-approved 2026-07-23):**
1. **Custody model = sealed-box escrow.** Offline recovery keypair; control plane holds only the public key; every node backup key is sealed to it automatically. Recovery private key lives in the owner's password manager. The same mechanism escrows the agent signing key (closes the parked to-do in cross-session running to-dos).
2. **CSRF posture = convert all state-changing GET links to POST + validate CSRF at a single dispatch point** (the Phase 3 logic extraction). Bookmarked GET action links stop working; that is accepted.
3. **Marketplace permission floor raised 8 → 10.**

**Executor ground rules (apply throughout):**
- Bump version headers in every file touched (script `#Version` lines, `plugin.json` version, JS asset versions for cache busting).
- `php -l` + `validate_php_file.php` every touched PHP file. **Exception:** never run `validate_php_file.php` on `utils/` or CLI scripts with run-on-include bodies (it executes the target) — `php -l` only for those.
- Never commit; leave everything staged-ready.
- All new tests use the shared harness with `@joinery-test` headers (see `docs/testing.md`). Shell gates are `*_gate.sh`.
- Secrets never in job records, logs, or chat output. Job `cmd` strings are persisted forever in `mjb_commands` — no key material may appear in them.
- Docs are updated in the same phase as the behavior they describe (Phase 8 lists them; do not defer past the phase that changes behavior). Docs describe current state only — no "previously/now/replaces" language.
- No timeline estimates anywhere.

---

## Phase 0 — Immediate one-liners (land first, independent of everything)

| Task | Fix | Acceptance |
|---|---|---|
| 0.1 | `plugins/server_manager/tasks/ProvisionCustomerCloud.php:49-56` — add `require_once(PathHelper::getIncludePath('includes/EmailSender.php'))` to the require block (P-1: `EmailSender::quickSend` at `:444,:551,:576` currently throws class-not-found, swallowed by `catch (\Throwable)`; buyer re-connect + ops alert emails silently never send). | Grep confirms require present; task tier test (Phase 6) covers it. |
| 0.2 | `plugins/server_manager/views/admin/node_detail.php:932` — `$resolved_ip` is undefined; real variable is `$resolved_ips` (array, `:869`). Render `implode(', ', $resolved_ips)` (U-1: SSL card's Resolved row always empty). | Browser-verify the SSL Setup card on a node with a domain shows resolved IPs. |
| 0.3 | `plugins/server_manager/logic/admin_marketplace_logic.php:11` — `check_permission(8)` → `check_permission(10)` (decision 3). | Permission-8 test user gets redirected. |
| 0.4 | `plugins/server_manager/views/admin/marketplace.php:49,53` — S-1 reflected XSS: `$message`/`$error` rendered unescaped and populated from raw request input (`admin_marketplace_logic.php:43-44`). Escape at output; the one intentional HTML error string (logic line 19) becomes a flagged constant rendered separately or rewritten to plain text. | `?message=<script>` renders inert. |
| 0.5 | `includes/JobResultProcessor.php:519-522,471-472` — stale comment says relay row is created disabled; code at `:571-576` deliberately enables it. Fix the comment to match the code (P-20) so nobody "fixes" the code the wrong way. | Comment matches behavior. |

---

## Phase 1 — Single restore engine (Defect B + the four sibling breakages)

**Goal:** one implementation of DB restore, used by every path, that decrypts, verifies before dropping, and fails loudly. Retires B-1..B-5 from the audit.

### 1.1 `restore_database.sh` becomes the engine (rewrite to this contract)

File: `maintenance_scripts/sysadmin_tools/restore_database.sh` (bump `#Version`).

Interface:
```
restore_database.sh DB_NAME FILE [--non-interactive] [--key-file PATH] [--db-user USER]
```
- `--db-user USER` (default `postgres` for standalone/manual use): all psql/pg_dump invocations use this user with `PGPASSWORD` from the environment. The dashboard always passes the site user from the creds preamble — removes the hard-coded `-U postgres` dependency on the site-password-equals-postgres-password invariant (`restore_database.sh:216,254,310,333,347,379,394`; invariant documented at `JobCommandBuilder.php:1563-1567`).
- `--key-file PATH` (explicit key transport): decryption key read from PATH. Resolution order: `--key-file` → `$BACKUP_ENCRYPTION_KEY` → `~/.joinery_backup_key` → (interactive prompt only when not `--non-interactive`). `$HOME`-relative implicit lookup is what broke under sudo (B-2) — the dashboard path always passes `--key-file` explicitly.
- **Replace semantics, not dropdb:** `DROP SCHEMA public CASCADE; CREATE SCHEMA public;` then load with `psql -v ON_ERROR_STOP=1` (matches the inline contract the builder tests already enforce, `tests/job_command_builder_test.php:629-673`). Removes the superuser requirement and fixes B-4 (`:394` currently loads with `> /dev/null 2>&1`, no ON_ERROR_STOP — partial restores report success).
- **Verify before destroy:** decrypt (if `.enc`) and `gunzip -t` to a temp file **before** any DROP. A bad key, truncated file, or corrupt archive exits with the database untouched.
- **All informational output to stderr; stdout reserved for machine-readable markers.** This is the composability rule whose absence caused B-1.
- Terminal markers on stdout (result-processor-parseable): `RESTORE_OK`, `BACKUP_KEY_MISSING`, `DECRYPT_FAILED`, `ARCHIVE_CORRUPT`, `RESTORE_LOAD_FAILED`.
- Remove the `eval "$output_var='$temp_file'"` returns (`:167,182,192,197`) — quote-unsafe (audit finding 13); use direct variable assignment or stdout-marker + tempfile convention.
- Keep: connection termination, pre-restore auto-backup (but see 1.4 — the builder's own auto-backup step becomes the only one on dashboard runs; script-level auto-backup runs only when invoked manually without `--non-interactive`... simpler and chosen: script auto-backup is skipped when `--non-interactive`, because the builder always prepends its own).

### 1.2 `restore_project.sh` fixes

File: `maintenance_scripts/sysadmin_tools/restore_project.sh` (bump `#Version`).
- **B-1 (critical):** `verify_archive()` currently prints info lines to stdout around the one meaningful `echo "$backup_dir"`; the capture at `:495` gets a multi-line blob, every directory test fails, and the script exits 0 having restored **nothing** ("RESTORE COMPLETE"). Fix: route all `print_*` helpers to stderr (same rule as 1.1); `verify_archive` emits only the path on stdout. Under `set -e` the failing-capture branch at `:495-500` is also unreachable — restructure so verification failure produces a real error exit (audit finding 17).
- Pass `--key-file` and `--db-user` through to `restore_database.sh` for the inner DB stage.
- The interactive "decline DB restore aborts remaining stages" bug (`:322-325` returns from `perform_restore`) — declining one stage must not skip the others (audit finding 15).

### 1.3 Builder paths converge on the script

File: `plugins/server_manager/includes/JobCommandBuilder.php`.
- `build_restore_database()` (`:727-779`): replace the inline verify+drop+load steps (`:764-772`) with a call to `restore_database.sh --non-interactive --db-user "$DB_USER" --key-file "$KEY_PATH"` after the creds preamble. `KEY_PATH` is resolved in a **non-sudo** step (`KEY_PATH=$HOME/.joinery_backup_key`) and passed absolute into any sudo context — this is the B-2 fix pattern; apply it identically to the project-restore invocation at `:858`.
- From-backup install DB stage (`:1656`): same engine call (it can receive `.enc` dumps today and dies at `gunzip -t` after the fresh site is already installed — audit finding 9). The backup-picker UI should also warn when an `.enc` file is selected for a target node with no key (see Phase 2 inventory data).
- Keep the builder's own auto-backup-before-overwrite step and the download step; add the missing `mkdir -p /backups` to `build_restore_database`'s auto-backup (audit finding 11, sibling inconsistency — becomes moot if R-3's `step_ensure_backup_dir` helper lands here, which is the preferred fix).
- `build_copy_database` / `build_copy_database_by_name` inline restores (`:648,:709`) may stay inline (they operate on plaintext dumps they just created) — but must use the shared `step_restore_replace` helper from Phase 7 so there is exactly one string to drift.

### 1.4 Cloud transfer fixes (B-5)

- `plugins/server_manager/includes/S3Signer.php:19,146-153`: GET requests currently capped at `TIMEOUT_SECONDS = 15` (only PUT gets 3600). Downloads get the long timeout too.
- `node_uploader.php:58-72` (emitted by `build_node_uploader_script`): stream downloads to file (`CURLOPT_FILE` / chunked fwrite), never whole-body-in-RAM — multi-GB archives must not OOM the node.

### 1.5 Restore-adjacent backup fixes

- **P-8:** `backup_database.sh:74` — filename granularity `%m_%d_%Y` → full timestamp (`%Y%m%d_%H%M%S`), matching `backup_project.sh:164`. Same-day backups must not overwrite each other locally or in the bucket.
- **P-9:** plaintext dashboard DB backup writes uncompressed `.sql` invisible to every glob (`backup_database.sh:125` vs globs at `JobCommandBuilder.php:523,1042,1125`). Fix: plaintext path writes `.sql.gz`, and the globs move to one shared constant (Phase 7).
- **P-10:** `build_backup_project` (`JobCommandBuilder.php:564`) runs without the creds preamble; the script then self-harvests config it may not be able to read as the unprivileged SSH user (`backup_project.sh:272-278,327-335`). Emit `get_db_credentials_script()` before every script invocation, and verify once live on a bare-metal node.
- **P-23:** upload/delete-local steps each recompute `NEWEST_BACKUP` independently (`:1042,:1056,:1064`) — compute once, reuse via shell variable within a single step, so a file landing in between can't be deleted un-uploaded.

### 1.6 Phase 1 tests (the audit's structural gap: nothing ever *executes* a restore)

New shell gates in `plugins/server_manager/tests/`:
- `restore_roundtrip_gate.sh` — on the local box: create throwaway DB → `backup_database.sh` (encrypted) → `restore_database.sh --non-interactive --key-file` into a second throwaway DB → row-count equality. Repeat plaintext. Repeat with a **wrong key** and assert `DECRYPT_FAILED` with target DB untouched. Repeat with a truncated archive and assert `ARCHIVE_CORRUPT` with target DB untouched.
- `restore_project_capture_gate.sh` — asserts `verify_archive` emits exactly one line on stdout (the B-1 regression trap).
- Extend `tests/job_command_builder_test.php`: restore steps must contain `restore_database.sh` + `--key-file`; must NOT contain inline `DROP SCHEMA` in `build_restore_database`; from-backup install step likewise.

**Phase 1 acceptance:** all gates green; live on dev — encrypted cloud backup of a real node restored through the Backups-tab button end-to-end; project restore verifiably changes the target (B-1's "green job, nothing restored" is impossible to reproduce).

---

## Phase 2 — Sealed-box escrow (Defect A) + agent signing key

**Approved design:** control plane can *seal* (public key) but never *unseal* (private key offline). Compromise of control plane or B2 yields only sealed blobs.

### 2.1 Recovery keypair tooling (owner runs once, locally)

New CLI: `maintenance_scripts/sysadmin_tools/escrow_keypair.php` (plain PHP + sodium, runnable on any machine; `php -l` only — do not run the validator on it).
- `generate` mode: `sodium_crypto_box_keypair()`; writes the private key to a mode-600 file path given by the operator, prints **only the public key** (base64) to stdout. Never prints the private key (secret-handling rules).
- `unseal` mode: reads sealed blob (file/stdin) + private-key file, prints the recovered backup key. This is the DR tool; it runs on the operator's machine, never on the control plane.
- Owner stores the private key file's contents in the password manager (decision 1) and deletes the local file, or keeps it offline. The public key is pasted into the setting below.

### 2.2 Control-plane storage

- New setting `server_manager_escrow_public_key` (base64), declared in `plugins/server_manager/plugin.json` `settings` (declarative seeding; empty default).
- New data class `plugins/server_manager/data/backup_key_escrow_class.php` (+ Multi), table `bke_backup_key_escrow`, prefix `bke_`:
  - `bke_escrow_id` (PK), `bke_mgn_node_id` (FK managed_nodes, `foreign_key` field-spec), `bke_key_fingerprint` (varchar 64 — sha256 hex of the raw key string), `bke_sealed_blob` (text, base64 `sodium_crypto_box_seal` output), `bke_kind` (varchar 20: `backup` | `agent_signing`), `bke_source` (varchar 20: `generated` | `migrated` | `rotated`), `bke_create_time`.
  - **Append-only:** no update/delete surface anywhere; rotation adds rows (spec's open decision 3 — old archives stay decryptable). Deletion strategy: rows survive node soft-delete (they are the recovery record for that node's archives in B2); document in the class header.
- Follow `docs/example_class.php` conventions; validation duplicated into `save()` per the prepare()-not-guaranteed rule.

### 2.3 Key generation moves to the control plane

- New helper `plugins/server_manager/includes/BackupKeyEscrow.php`:
  - `ensureNodeKey(ManagedNode $node): string` — if an escrow row exists for the node's current key, no-op. Otherwise: generate `base64_encode(random_bytes(32))`, seal to the public key, **save the escrow row first**, then push the key to the node (write `~/.joinery_backup_key`, chmod 600) over the direct SSH channel (see 2.4 transport rule). Escrow-before-push ordering is the invariant: a key that exists on a node without an escrow row must be impossible on this code path.
  - Throws with a clear message if `server_manager_escrow_public_key` is empty — **encrypted backups refuse to run unescrowed** rather than silently reverting to node-only custody.
- `JobCommandBuilder` ensure-key steps (`:507-508` and `:554-556`) change from generate-if-missing to **verify-or-fail**: `[ -s ~/.joinery_backup_key ] || { echo BACKUP_KEY_MISSING; exit 1; }`. Node-side generation is deleted — the auto-generate-and-forget footgun is gone. Job creation for encrypted backups calls `BackupKeyEscrow::ensureNodeKey()` first (in the logic layer, not in a job step).
- Backup jobs append a fingerprint step: `sha256sum ~/.joinery_backup_key` → emits `BACKUP_KEY_FPR=<hex>`; `JobResultProcessor` compares against the newest `bke_` row for the node and records a health problem on mismatch (a manually regenerated node key is detected on the next backup, not at restore time).

### 2.4 Migration of existing node keys

- Admin action per node (Backups tab): **Escrow existing key**. Implementation reads the node's key over the direct SSH channel (the transport `node_exec.php` uses), in-process — **never via a ManagementJob** (job `cmd`/output rows are persisted; the key must not be). Seal → save row (`bke_source='migrated'`). Auto-run this for every node with a backup target during the phase (one-time loop in a migration or an admin utility button "Escrow all").
- Docker-node note (audit: only key copy lives inside the container): escrow *is* the durability fix; no host-side copy needed once the row exists.

### 2.5 Offsite replication of sealed blobs

- On every escrow row creation, upload the blob to each of the node's enabled cloud targets as `escrow/{node_slug}/{fingerprint}.sealed` via the existing `S3Signer` from the control plane. Also re-upload missing ones opportunistically when backup jobs complete (result processor checks). DR must survive the control plane being the casualty: blob + B2 credentials + password-manager private key are sufficient to decrypt any archive.

### 2.6 Inventory / health surfacing (spec's open decision 4 — yes)

- `NodeMonitorHealth` (or its dashboard consumer) gains a problem type: node has a backup target (encryption forced) and **no** escrow row → "Backup key not escrowed — offsite backups unrecoverable if this node is lost." Fingerprint mismatch (2.3) surfaces as its own problem. Same visual pattern as broken-monitoring surfacing.

### 2.7 Agent signing key escrow (closes the parked to-do)

- Seal the agent signing secret key (`AgentDistPublisher::ensureKeys`, `includes/AgentDistPublisher.php:198-201`) to the same public key; store as a `bke_` row with `bke_kind='agent_signing'`, `bke_mgn_node_id` NULL (make the FK nullable). One-time admin utility action + automatic on future key creation. While in that file: fix the chmod-window (write with 0600 via `touch`+`chmod` before content, or `file_put_contents` then immediate chmod with failure check) and `catch (Exception)` → `catch (Throwable)` (P-21).

### 2.8 DR runbook

- New doc section (Phase 8): exact recovery procedure — fetch `escrow/...sealed` from B2, `escrow_keypair.php unseal`, provision replacement node, write key file, restore via dashboard. Include the "control plane dead" variant (blobs are in B2).

### 2.9 Phase 2 tests

- `tests/backup_key_escrow_test.php` (db tier): seal/unseal round-trip with a throwaway keypair; escrow-before-push ordering (simulated push failure leaves the row — never the reverse); refusal when setting empty; append-only rotation (two rows, both blobs unseal); fingerprint mismatch detection through `JobResultProcessor` with a fabricated job result.
- Extend `job_command_builder_test.php`: ensure-key step asserts verify-or-fail shape, asserts **no** `openssl rand` remains anywhere in builder output.

**Phase 2 acceptance:** every node with a backup target has an escrow row; dashboard shows zero "not escrowed" flags; a sealed blob pulled from B2 unseals on a separate machine with the password-manager key and decrypts a real archive from that node (full DR drill on one dev/prod node).

---

## Phase 3 — Admin-surface security

The security fixes below landed directly in `node_detail.php` and the sibling views — they did not need a structural refactor. The organizational split of `node_detail.php` (dispatcher + tab partials + `latestForNode` dedup + shared JS + the R-3/U-3 correctness bugs) is tracked separately in **`server_manager_node_detail_extraction.md`** and built before the 1.0 big-testing round.

### 3.2 GET-mutation conversion (decision 2, approved)

Convert to POST single-button forms (hidden inputs + submit — the allowed FormWriter exception), each validated by an `SmAdminCsrf` guard in its handler:
- node soft-delete (`node_detail.php`), backup-target delete + test (`targets.php`), job cancel + rerun (`job_detail.php`), publish-upgrade delete/keep/prune/publish (`views/admin/publish_upgrade.php`). Keep `JoineryModal.confirm` guards where present. (`SmAdminCsrf` — per-session token, `field()`/`valid()` — replaces the abandoned single-dispatch `validateCSRF` plan, since the handlers stayed in place.)
- Also stop saving the node during GET dashboard loads (`JobCommandBuilder::fetch_status_via_api` `:274` writes on read — move the persist to the poll endpoint or make it explicitly allowed and documented).

### 3.3 XSS fixes

- **S-2** `node_add.php:207-244,302-315`: all discovery-result fields (`inst.*`, `r.hostname`, `data.error_message`, `e.slug/message`) rendered via `esc()`/`textContent`; replace `JSON.stringify(inst)` inside single-quoted onclick (`:217,:238` — `'` breaks out) with a data-index lookup into a JS array (no serialized objects in attributes).
- **S-3** `node_detail.php:1742,:1627,:1659`: entity-encoded text inside onclick JS strings — browser decodes entities before JS parses; node names arrive verbatim from remote discovery (`add_discovered_nodes_logic.php:58`). Replace inline onclick payloads with `data-` attributes + `addEventListener`, values escaped once for HTML.
- Audit finding 20 sweep: server-supplied message strings into `innerHTML` → `textContent` (`node_detail.php:1472,1480`, `node_add.php:159,188,202,209`); escape `$step['type']` at `job_detail.php:246`.

### 3.4 Backup-target credential handling (S-5)

`targets.php:227-241,102-117`: adopt leave-blank-to-keep for **all** secret fields (pattern exists at `node_detail.php:353-358`); never prefill any secret into HTML (the B2 `cred_app_key` echo violates secret-handling doctrine). Saving with blank secret keeps the stored value; there is no path that silently wipes credentials.

### 3.5 Small security minors

- **S-6:** whitelist sort columns before they reach the order-by key (`jobs.php:20,36`, `node_detail.php:1768` — `SystemBase.php:2069-76` interpolates the column name raw).
- **S-8:** two secrets leak at rest. Fixed by SecretBox-at-rest, display redaction, and — for the cloud credentials that the agent needs at run time — runtime resolution in the agent so `mjb_commands` is literally secret-free.
  - **Pipeline API secret** (`ProvisioningSetup.php`): stored SecretBox-encrypted in settings, decrypted through a single `ProvisioningSetup::readApiSecret()` accessor that every consumer (`PollHostingOrders`, `JobResultProcessor::send_provisioning_welcome_email`, `probeApi`) reads through. A legacy plaintext value passes through unchanged (lazy migration).
  - **Cloud backup-target credentials.** Root cause: `bkt_credentials` is stored as **plaintext JSON** in the jsonb column, and `JobCommandBuilder::build_node_uploader_script` (`:1108-1118`) `var_export`s that plaintext into the step `cmd`, which persists in `mjb_commands` forever and renders on `job_detail.php` to every permission-10 admin. Fix both layers:
    - **Encrypt at rest** — `BackupTarget` stores `bkt_credentials` SecretBox-encrypted and decrypts in `get_credentials()`; a legacy plaintext JSON value passes through (lazy migration). This makes the encrypted target the canonical store.
    - **Runtime resolution in the agent (Option C).** The builder never writes credentials into a step `cmd`. `build_node_uploader_script` emits `$creds = json_decode(base64_decode('__SM_CREDS_<target_id>__'), true);` — a placeholder token, not a secret. The Go agent, immediately before running any `ssh`/`local` step, scans `step.Cmd` for `__SM_CREDS_<id>__` tokens; for each it loads `bkt_credentials` for that target, SecretBox-decrypts it (a new `nacl/secretbox` + AES-GCM decrypt path in Go, matching `SecretBox`'s `v1.sodium`/`v1.aesgcm` wire format, keyed by the `secret_box_key` the agent already reads from `Globalvars_site.php`), and substitutes `base64(json(creds))`. `mjb_commands` holds only the placeholder — verified secret-free by test.
      - **Rollout gate.** Placeholder emission is capability-gated: the builder emits a placeholder only when the control plane's own agent heartbeat reports a version that resolves them (`>= 0.4.0`); otherwise it falls back to the inline `var_export` path. Because the agent ships in the platform release and self-updates, the gate opens automatically once the shipped agent lands — no flag day, and a not-yet-upgraded control plane keeps producing working (if inline-credential) backups rather than broken ones. The fallback path is transient and self-heals.
    - **Redact in the UI** (defense in depth) — a shared `SmSecretRedactor` masks credential *values* (var_export, JSON, and `secret-key:` header shapes) in text bound for the screen. Applied to raw job output at both surfaces that emit it: the initial `job_detail.php` render and the `job_status_logic` poll tail. The Steps card renders only step `label`/`type` (never `cmd`), so there is no command display to redact; the redactor guards output and any future command surface, and still covers the inline-credential fallback path during agent rollout.

### 3.6 Phase 3 tests + verification

- Extend/keep the injection-inertness suite: a node named `x',alert(1),'<img src=x onerror=alert(1)>` renders inert on every tab and in discovery results (browser-verify via Playwright).
- CSRF: POST without token → rejected, with token → accepted (harness test against the dispatch).
- Pipeline API secret and `bkt_credentials` are both stored SecretBox-encrypted at rest; each is readable back through its accessor / `get_credentials()`; a legacy plaintext value still reads correctly (`provisioning_setup`, `backup_target_encryption` db-tier tests).
- `SmSecretRedactor` masks every credential value (var_export, JSON, header shapes) while leaving structure and non-secret fields intact (`secret_redactor` safe-tier test).
- With a placeholder-capable agent, a cloud backup/upload job's `mjb_commands` contains a `__SM_CREDS_<id>__` token and **no** credential material; with an older agent the inline `var_export` fallback is emitted (`job_command_builder` db-tier test, both branches).
- The agent's SecretBox decrypt (`creds_test.go`) opens a `v1.sodium` and a `v1.aesgcm` blob produced by PHP `SecretBox::encrypt`, and placeholder substitution replaces every token with `base64(json(creds))` (Go unit test).

**Phase 3 acceptance:** all existing browser flows re-verified on dev (each tab, each action button); hostile-name node renders inert; no GET link mutates anything.

---

## Phase 4 — Pipeline majors

- **P-2 — result-processing claim lock.** `JobResultProcessor::process()` (`includes/JobResultProcessor.php:19-36`): atomic claim before side effects — `UPDATE management_jobs SET mjb_result='processing' WHERE mjb_job_id=? AND mjb_result IS NULL` (or a dedicated claim column if `processing` collides with result parsing); rows affected 0 → another processor owns it, return. Every terminal path then overwrites with the real result — which also fixes **P-16** (failed backups never write `mjb_result`, reprocessed every 2s poll, `:270-273`): apply the every-terminal-path-records rule the file documents for apply_update (`:385-390`) to all job types. Callers to re-check after: `views/admin/index.php:36-39`, `logic/job_status_logic.php:47-51`, `tasks/ProvisionCustomerCloud.php:314-317`, `tasks/ProvisionPendingSsl.php:80-88`. Double-send of the welcome email / rDNS / WireGuard peer exec becomes impossible by construction.
- **P-3 — publish integrity.** `includes/publish_upgrade.php`: check every `$exit_code` from rsync (`:299`; exit 24 = vanished-files is acceptable, others fatal) and tar (`:337`, theme/plugin at `:488,:585`); reset `$output = []` before each `exec()` (`:192,:299,:337,:488,:585` — exec appends, P-13-adjacent audit finding). Reorder: build + verify **all** archives into a staging name first, then write VERSION / Upgrade row / `system_version` (`:148-373` currently commits identity before artifacts). Add a publish lockfile (same pattern as the upgrade.php concurrency lock, commit 8a4f7829). A failed publish leaves no claimed version.
- **P-4 — stuck-job watchdog.** New check in the existing scheduled-task family: jobs in `pending`/`running` older than a per-type timeout (default: step timeout sum + margin; apply_update/install 2h, others 30m) → mark `failed` with `TIMED_OUT` marker + surface via NodeMonitorHealth problem + alert email (reuse `resolve_alert_recipient` — Phase 7 consolidates it). Downstream state machines (`ProvisionPendingSsl.php:70-73`, `ProvisionCustomerCloud.php:309-311`) then see a terminal state instead of waiting forever.
- **P-5 — order poll window.** `tasks/PollHostingOrders.php:46-49`: page through `OrderItemRequirements` (loop `pagenumber` until short page) or filter server-side to unhandled ids; either way the 200-all-time-rows ceiling goes away. Log the scanned/handled counts (no silent caps).
- **P-6 — Cloudflare-proxied SSL.** `tasks/ProvisionPendingSsl.php:109-117`: detect Cloudflare the same way the builder does (`JobCommandBuilder::is_cloudflare_domain`, `:1236-46` context) and dispatch the job for those domains so the builder's existing CF branch (proto patch, no certbot) finally runs; keep the A-record gate only for non-CF domains. Also: add `enabled`-node filter (`:27-31`) and measure the 16-hour give-up from the newest *streak* of failures, not the first job ever (`:100`).
- **P-7 — `.pub` derivation.** `includes/ProvisioningSetup.php:315-320`: check exec return code AND non-empty output before writing `key.pub`; `ProvisionCustomerCloud.php:70,113` additionally refuses to create instances with an empty/unreadable pubkey (no more customer-billed unreachable boxes).
- **P-11/P-12** (`ProvisionCustomerCloud.php`): boot-timeout clock must not reset on transient-error saves (`:158-166,:524-25` — store `cvp_boot_started_time` once, measure from it); store the dispatched verification job id on the provision row and bind `handle_installing` to it (`:291-306`), which also fixes the bare-provision recheck gap (`:371-374` only queries `install_node`).
- **P-17 — sweep list.** `views/admin/index.php:31`: derive the processable-job-type list from `JobResultProcessor` (`method_exists`) instead of the hard-coded trio; unwatched relay jobs stop rotting.

Task-tier tests accompany each (the three provisioning tasks currently have **zero** state-machine coverage — audit finding 14): fabricate provision rows + job rows, tick the task, assert transitions for: happy path, stuck-job timeout, Cloudflare domain, empty pubkey refusal, >200-answer paging.

---

## Phase 5 — Remaining minors sweep

Work straight down this list; each is one small verified fix at the given anchor.

- P-13: `ProvisionCustomerCloud.php:496` hardcodes `LinodeComputeDriver` for any registered provider — route through a driver factory keyed on `cvp_provider` (Phase 7 extracts `CustomerCloudDrivers::forAccount()`, which is where this lands).
- P-14: validate buyer domain answers with the FQDN regex already at `NodeReverseDns.php:83` (`PollHostingOrders.php:79`, `CustomerCloudFulfillment.php:98-106`); reject → park order with alert, don't create junk nodes.
- P-15: `strtotime($t)` on UTC DB strings → `strtotime($t . ' UTC')` (`ProvisionPendingSsl.php:91,100`; pattern reference `ProvisionCustomerCloud.php:159`).
- P-18: pass the allocated port to install.sh — `$port_arg` is built empty at `JobCommandBuilder.php:1387,:1575` despite callers allocating one; and make allocation collision-safe (exclude ports of deleted-but-running containers or probe the host).
- P-19: capture downtime duration before `apply_state()` nulls `mgn_uptime_down_since` (`RunNodeUptimeChecks.php:298` vs `:335-336`) — "recovered after unknown" gets a real duration.
- P-22: `node_exec.php:147-148` — wrap non-stdin container commands in `bash -c` so pipes run inside the container like the stdin path does.
- Audit findings 9/10 (JobResultProcessor): don't flip `mgn_ssl_state` active→failed on a single 4s probe timeout (`:126-130` — require 2 consecutive failures or lengthen timeout); `$api_data = []` envelope must not fall through to SSH-regex parsing while `$is_api_path` stays true (`:56-63`).
- Audit finding 17 (JobResultProcessor `:468`): set `mgn_wg_ip` only on the non-skeleton fork.
- U-2: stop reusing the HTML-escaped filename as posted data (`node_detail.php:1340,:1374-77`) — escape only at render.
- U-4: `install_node_form.php:445` vs `:461` — use one hide mechanism (class toggle), fields always recoverable.
- U-5: recent-DB-ops query filters by `job_type` in SQL (`node_detail.php:1667-81`); job-type filter lists generated from one shared PHP array used by both `node_detail.php:1791` and `jobs.php:87` (all job types present).
- U-6: replace the dead Bootstrap accordion on `index.php:246-68` with the vanilla details/summary or class-toggle pattern; replace FontAwesome classes on marketplace with theme-native markup (vanilla rule).
- U-7: wrap `new ManagementJob($id, TRUE)` / `new ManagedHost(...)` in the redirect-on-missing pattern from `node_detail.php:34-39` (`job_detail.php:27`, `host_add.php:16`).
- U-3 residue: polling never freezes silently — on API/transport error, retry with capped backoff (max ~5) then show a visible "polling stopped — reload" notice (`job_detail.php:271`, `node_detail.php:1494-1509`, `node_add.php:246-48`); the backups-tab error envelope must not fake "Scan complete" + reload (mints duplicate `list_backups` jobs).
- `node_add.php:34-35`: delete the tautological dead assignment.
- `publish_theme.php:200`: core filename built without patch version — dead branch; build `major.minor.patch` (P-6 minor from pipeline agent).
- `install_agent.sh:169-178`: sha256/decompress failure currently exits 0 with a WARNING — keep exit 0 (contract) but emit a distinct `AGENT_UPDATE_CORRUPT` marker the result processor records as a node health problem (no silent fleet-wide stall).
- ManagedNode/ManagedHost: duplicate `prepare()` validation into `save()` (the platform rule; `CustomerCloudProvision` at `data/customer_cloud_provision_class.php:82-86` is the house pattern) — fixes reused-failed-node saves skipping validation (`PollHostingOrders.php:187-197`, `ProvisionCustomerCloud.php:215-218`).
- Restore-path input validation: `backup_local_path` POST accepted raw (`node_detail.php:128-137`) — apply the `#^/backups/[^/]+$#` check `backup_actions_logic.php:52` already uses for delete.

---

## Phase 6 — Dead code removal

- `includes/publish_upgrade.php`: `getDirContents()` + `create_zip()` (`:874-994`, nothing calls them since the tar migration) and unused `$verbose` (`:145`) — delete (~120 lines).
- Near-identical theme-archive and plugin-archive loops (`:429-496` vs `:527-593`) → one `publish_component_archives($kind)` helper.
- `publish_theme.php` `?list=themes` / `?list=plugins` twins (`:29-90`) → one parameterized block.

---

## Phase 7 — Consolidation refactors (each one removes a drift axis that produced a real bug above)

- **R-3 — JobCommandBuilder step helpers** (existing `job_command_builder_test.php` string assertions are the regression harness; update expectations as you go):
  - `step_ensure_backup_dir($node)` — 8 sites (`:513,:561,:598,:703,:838,:847,:1423,:1652`); also gives `build_restore_database` the missing mkdir.
  - `step_auto_backup_db(...)` — 5+1 sites (`:598,:703,:737,:838,:1652`, tar variant `:847`).
  - `step_restore_replace($creds, $dump_path)` — the copy-path inline restores (`:648,:709`) after Phase 1 moves the rest to the script.
  - `cloud_php_cmd($target, $verb, ...$args)` — 4 heredoc assembly sites (`:748-752,:823-827,:1046-1048,:1156-1159`).
  - `step_teardown_rm($paths)` — ~11 identical teardown shapes (`:658-682,:711,:999,:1726-1740,:1750-1759,:1765`).
  - `new_transfer_id()` — 5 sites (`:585,:697,:990,:1371,:1883`).
  - `creds_pipeline()` — the config-parse pipeline appears verbatim 4× in the builder (`:362,:1186,:1585,:1643`) with divergent variants at `:1545`, `:1803-1806/:1822-1825`, and `copy_database.sh:49`; collapse the builder's four now, converge the scripts on a shared `read_site_config.sh` as a follow-on inside this phase.
- **R-4 — shared task helpers** (land WITH the Phase 4 task tests): `resolve_alert_recipient` (2 verbatim copies: `RunNodeUptimeChecks.php:359`, `ProvisionCustomerCloud.php:586`), `slugify` (4 implementations — `PollHostingOrders:106-109`, `CustomerCloudFulfillment:109-112`, `ManagedHost::prepare:34-37`, `ManagedNode::prepare:90-92`, the last one divergent), error-summary tail (3 copies), `EmailSender` try/catch wrapper (3 copies in one file), `CustomerCloudDrivers::forAccount()` (2×~40-line OAuth→token→driver flows: `ProvisionCustomerCloud::get_driver:461-497`, `NodeReverseDns::driverForProvision:129-168`; failure semantics — park vs throw — stay at the caller).
- **R-6 — builder altitude**: move `status_color_for_node` / `fetch_status_via_api` / `probe_https` (`:200-341`) out of JobCommandBuilder next to `NodeMonitorHealth`; extract `management_api_get($node, $endpoint, $timeout)` from the ~55 duplicated curl lines (`:107-186` vs `:200-278`); extract the has_api→has_ssh→throw dispatch (2 copies: `:392-403`, `:1103-1114`).
- **R-7 — self-defending shell builder**: `assert_shell_safe_sitename()` guard at the top of `build_install_node` (charset is currently enforced only by a distant form validator, `install_node_form.php:52`); escapeshellarg the ~6 raw `$sitename`/`$project_name`/`$scripts` interpolations where the escaped variable already exists (`:516,:564,:894,:1430,:1614,:1671,:1698,:1775`).
- Shared constant for the backup-file glob (3 copies: `:523,:1042,:1125`) — Phase 1.5 P-9 depends on it.

---

## Phase 8 — Documentation (same-state-only rules apply)

- `plugins/server_manager/docs/overview.md` § Backup Encryption: rewrite to describe sealed-box escrow as the custody model (key generation at target assignment, escrow rows, fingerprint verification, health flags), the single restore engine and its markers, and the DR runbook (2.8) including the control-plane-dead variant.
- `docs/deploy_and_upgrade.md`: publish integrity behavior (artifact-before-identity ordering, lock, exit-code enforcement).
- `docs/testing.md` index entry if the new shell gates introduce a pattern worth naming.
- Update `plugins/server_manager/plugin.json` version once at the end of the package.

---

## Final acceptance (the 1.0 bar)

1. `php tests/run.php safe` and `db` fully green, including all new suites/gates.
2. **DR drill (the headline scenario from the custody spec):** using only (a) the B2 bucket contents and (b) the password-manager recovery key — no access to the original node — restore a real node's database onto a fresh target through the dashboard. This is the scenario that motivated the whole package; it must demonstrably work.
3. Encrypted project restore verified to actually replace content (B-1 impossible).
4. Hostile-node-name browser sweep inert; no GET mutation reachable; CSRF enforced on every plugin POST.
5. Publish on dev with an artificially failing tar aborts with no version claimed.
6. Zero "backup key not escrowed" flags across all managed nodes; agent signing key has an escrow row.
7. Move this spec and `specs/backup_key_custody_and_recovery.md` to `specs/implemented/` (with the owner's commit go-ahead).

## Suggested execution order

Phases are ordered by dependency and risk: 0 → 1 → 2 → 3 → 4 → 5/6 (interleave freely) → 7 → 8. Phase 7's R-3/R-6 may be pulled earlier where a Phase 1/4 task touches the same lines (e.g. `step_ensure_backup_dir` during 1.3). Live-verify on dev after each phase; the DR drill closes the package.
