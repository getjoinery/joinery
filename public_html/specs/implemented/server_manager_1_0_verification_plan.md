# Server Manager 1.0 Verification Plan — Autonomous Live-Test Runbook

**Status:** SUPERSEDED by `server_manager_1_0_test_plan.md` — the executed 5-VPS evolution of this runbook (this 4-VPS draft was never run). Retained for history.
**Executed by:** an autonomous agent on the dev control plane (`dev.getjoinery.com`), using the dashboard, the direct SSH channel, Playwright for browser checks, and the test suites.
**Companion:** `specs/server_manager_1_0_hardening.md` (what is being verified; finding IDs referenced throughout), `specs/backup_key_custody_and_recovery.md` (threat model behind the T7 DR drill).

---

## 1. Resources the owner provides up front

### 1.1 VPSes — exactly 4 required

| Slot | Role | Lifecycle |
|---|---|---|
| `vps_a` | Bare-metal primary node — install, backup, restore, upgrade, security sweep | Installed in T1, used throughout, deleted in T13 |
| `vps_b` | Docker shared host — 2 container sites; docker key/escrow paths, port allocation | Installed in T1, used throughout, deleted in T13 |
| `vps_c` | **DR target — must remain untouched until T7.** The drill's credibility depends on this box having no prior contact with the platform | First touched in T7 |
| `vps_d` | Bare-metal secondary — cross-node copy/clone target, monitoring/watchdog victim, hostile-name node | Installed in T1, used throughout, deleted in T13 |

An optional 5th VPS allows re-running the T7 drill without re-imaging `vps_c`; not required.

**Per-VPS requirements:** fresh Ubuntu 24.04 LTS; ≥2 vCPU / 2 GB RAM / ≥40 GB disk (T4.5 builds a multi-GB archive); root SSH login with the dev control plane's SSH public key pre-installed (the same key existing managed nodes use); ports 22/80/443 open; outbound HTTPS (B2 egress). No other software pre-installed.

### 1.2 Input file the owner passes

The agent starts from a single file, path given in the kickoff prompt:

```
run_id: smv-YYYYMMDD
vps_a: <ip>
vps_b: <ip>
vps_c: <ip>
vps_d: <ip>
# Optional — enables T9 (SSL). Omit and T9 is reported SKIPPED, not failed.
dns:
  a: <hostname pointing at vps_a>        # direct A record
  b1: <hostname pointing at vps_b>       # direct A record (container site 1)
  b2: <hostname pointing at vps_b>       # direct A record (container site 2)
  d: <hostname pointing at vps_d>        # direct A record
  cf: <hostname pointing at vps_d>       # PROXIED through Cloudflare (orange cloud) — exercises P-6
```

Without the `dns` block, T1 installs use IP-based/hosts-file access and T9 is skipped.

### 1.3 Already on the control plane (agent verifies in T0, does not create)

- Admin (permission 10) login — Claude memory credentials.
- A B2 backup target configured in the dashboard whose bucket (or prefix) is **dedicated to this run** and safe to purge in cleanup. Name must contain `smv-test` — the cleanup phase refuses to purge any target not matching.
- The built hardening package deployed on dev (all phases), suites green.

---

## 2. Hard safety rules (agent must enforce, every phase)

1. **IP allowlist.** Before any job, SSH command, delete, or destructive action against a node: resolve the node's IP and require it ∈ {vps_a..vps_d}. Existing managed nodes (getjoinery fleet, jeremytunnell, scrolldaddy, relays) are **never** touched, including by "all nodes" buttons — if a flow only offers fleet-wide actions, that is a FAIL finding, not a reason to proceed.
2. **Run tagging.** Every node, site, and DB this run creates is named with the `run_id` prefix (e.g. `smv20260801a`). Cleanup deletes only run-tagged artifacts.
3. **Escrow keypair isolation.** T0 generates an **ephemeral test recovery keypair** in the run workspace and swaps `server_manager_escrow_public_key` to it after saving the original value. The mechanism is what's under test, not the owner's secret — the owner's real recovery private key is never requested, and T13 restores the original setting. If the original was empty, restore empty.
4. **Secrets discipline.** No key material in the report, in chat, or in job records. Evidence for key-related tests is fingerprints (sha256), never values.
5. **DB writes on the control plane** (fabricating rows for negative tests) are pre-authorized for this run **only** for run-tagged rows and the `bke_`/`mjb_`/`mgn_` rows belonging to run-tagged nodes.
6. **Failure handling.** A failed step inside a phase: record FAIL with evidence, attempt that phase's own cleanup, continue with independent phases. Never blind-retry a destructive step. Phases marked `[blocking]` (T0, T1, T7-prep) halt the run if failed.
7. **Publish caution (T6).** Failure-injection publish tests run before the one real publish; the real publish happens once, at most, and applies only to run VPSes.

---

## 3. Reporting contract

- Running log: `specs/verification_runs/{run_id}_report.md` (create dir; chmod 666/777 per house rules) — one line per test: `T4.2 PASS|FAIL|SKIPPED — evidence (job #id, marker, screenshot path)`.
- Screenshots under `/tmp/playwright-mcp/` (house rule), referenced from the report.
- Final section: summary table, FAIL list with repro detail, coverage gaps encountered, cleanup confirmation.
- The traceability matrix (§5) is appended with actual outcomes — every hardening finding ID gets a verdict: PASS / FAIL / COVERED-BY-SUITE / SKIPPED(reason).

---

## 4. Test phases

### T0 — Preflight `[blocking]`
1. Parse input file; ping + SSH each VPS as root; verify fresh Ubuntu 24.04, empty `/var/www/html`, hostname/IP match the slot.
2. `php tests/run.php safe` and `db` green on dev; record platform version, agent version, git status snapshot.
3. Verify the `smv-test` B2 target exists and is empty (purge leftovers from a prior run after IP-allowlist-style name check).
4. Generate ephemeral recovery keypair (`escrow_keypair.php generate`); save original `server_manager_escrow_public_key`; set the test public key. Record fingerprint only.
5. Verify Playwright can log into the dashboard.

### T1 — Install & node onboarding `[blocking]`
1. **Bare install on `vps_a`** via the dashboard install form (run-tagged sitename, domain `dns.a` if provided). Pass: job green, site serves, node row created, agent installed and reporting via API transport (probe badge green), `mgn_port` semantics n/a for bare.
2. **Docker host on `vps_b`** with two container sites (b1, b2). Pass: both containers serve; **allocated ports actually reach install.sh and match `mgn_port` (P-18)** — verify `docker ps` published ports equal the recorded values; second site's port ≠ first.
3. **Bare install on `vps_d`** (domain `dns.d`). Same pass criteria as A.
4. Discovery/adoption check: run "Detect" against `vps_d` from node-add; discovered instance data renders (content correctness only — hostile-input rendering is T11).
5. Negative: install form rejects an invalid sitename charset (R-7 guard) with a clean error, no job created.

### T2 — Escrow (hardening Phase 2)
1. Assign the B2 target to nodes A, B(both sites' scope as applicable), D. Pass: for each node an escrow row (`bke_`) exists **before** any backup job has run; `bke_source='generated'`; sealed blob object `escrow/{slug}/{fpr}.sealed` present in B2.
2. Node-side key file exists (mode 600) and its sha256 equals `bke_key_fingerprint` — checked via direct SSH, value never logged.
3. **Docker node key durability intent:** escrow row exists for the container-based node (the audit's container-only-key hazard is closed by the row, not by file placement).
4. Unseal round-trip: fetch A's sealed blob from B2, `escrow_keypair.php unseal` with the test private key on the control plane workspace, compare sha256 to the node file's. Pass: match. (Values compared as fingerprints.)
5. Verify-or-fail: delete the key file on `vps_d`, run an encrypted backup. Pass: job fails fast with `BACKUP_KEY_MISSING`; no `openssl rand` anywhere in the job's commands (generation footgun gone). Then restore the key from the escrow blob (this is a mini-DR of the key itself) and confirm a rerun succeeds.
6. Fingerprint drift: overwrite `vps_d`'s key with a new random value directly, run a backup. Pass: `BACKUP_KEY_FPR` mismatch surfaces as a node health problem on the dashboard. Escrow the new key via the **Escrow existing key** action (`bke_source='migrated'`, append-only: old row still present), problem clears on next backup.
7. Refusal when unconfigured: temporarily blank the escrow pubkey setting, attempt an encrypted backup. Pass: refused loudly at job creation, no job runs. Restore test pubkey.
8. Agent signing key: `bke_kind='agent_signing'` row exists (created in hardening Phase 2.7); unseal it and fingerprint-compare against `config/agent_signing_key`.
9. Inventory flag: create a run-tagged node row with a backup target and no escrow row (fabricated). Pass: dashboard shows the "not escrowed" health problem. Delete the fabricated row.

### T3 — Backup flows (hardening Phase 1.5)
1. Encrypted DB backup on A → uploaded to B2; filename carries full timestamp; job green; `mjb_result` recorded.
2. **Immediately run a second backup (P-8).** Pass: two distinct objects in B2 and two local files — nothing overwritten.
3. Project backup on A (tar with inner encrypted dump) → B2.
4. **Bare-metal project backup runs with the creds preamble (P-10)** — pass: job green on A as the non-root SSH user context dictates (or on whichever slot is non-root); config archive included.
5. Plaintext backup path (no cloud target — use a run-tagged scratch DB/site or temporarily unassign): produces `.sql.gz`, appears in the Backups tab (P-9). Reassign target after.
6. Failed backup records a result: break the backup deliberately (e.g. bad DB name param if reachable, else stop postgres briefly on D). Pass: job marked failed **with `mjb_result` set** (P-16), and the status poll does not reprocess it (watch `mjb_` row stability over 30s).
7. Docker-node backup on B: key handling and upload work in-container; backup of site b1 does not touch b2's DB.
8. Delete-local-after-upload (if enabled on the target): local file gone, cloud object present; a file planted in `/backups` mid-window is not deleted (P-23 best-effort check: plant before upload step completes if timing allows, else verify single-computation in the job's command text).

### T4 — Restore flows, node alive (hardening Phase 1)
1. **Encrypted cloud DB restore via the Backups-tab button (B-3, the original Defect B).** Prep: write a run-tagged marker row, back up, delete the marker, restore from B2. Pass: `RESTORE_OK` marker; marker row is back; job green.
2. **Project restore actually replaces content (B-1).** Prep: after the T3.3 project backup, modify a file in the web root and a marker row; restore the project archive. Pass: file content and DB marker both reverted — verified by content hash, not job status. This is the regression trap for the silent no-op.
3. Wrong-key negative: temporarily swap A's key file with a random one, attempt restore. Pass: `DECRYPT_FAILED`; **target DB untouched** (marker state unchanged); key restored from escrow afterward.
4. Corrupt-archive negative: upload a truncated copy of a backup under a run-tagged name, restore it. Pass: `ARCHIVE_CORRUPT` before any drop; DB untouched.
5. **Large-archive restore (B-5).** Pad a run-tagged table to ≥2 GB compressed... practical target: enough that download exceeds 15 s (≥1 GB). Back up, restore from cloud. Pass: download streams to file (node RAM stays flat — spot-check `free` during), no 15s timeout, restore completes.
6. Restore on docker node B (site b1) from cloud: pass criteria as 4.1; b2 unaffected.
7. Pre-restore auto-backup exists exactly once per restore job (the doubled-dump waste is gone — count `auto_pre_*` files created by one job).
8. Sort/limit UI check while here: Recent Database Operations table lists these restores even after 20+ unrelated jobs (U-5).

### T5 — Cross-node flows
1. Copy database A → D (dashboard). Pass: D serves A's marker data; auto-pre-overwrite backup exists on D; teardown removed temp dumps on both.
2. From-backup clone install: build a run-tagged site on B (or reuse D) from A's existing dump via the install form's from-backup path. Pass: clone serves; encrypted-dump selection either restores correctly through the engine or warns before job creation (per hardening 1.3) — silent `gunzip` failure is a FAIL.
3. Copy project A → D if the flow exists in the dashboard; same content-hash verification as T4.2.

### T6 — Publish & upgrade (hardening Phase 4 P-3)
1. **Failure injection first:** shim `tar` (PATH override for the publish process) to exit 2; run a publish. Pass: publish aborts, **no VERSION bump, no Upgrade row, no `system_version` change** — identity untouched (P-3 ordering). Remove shim.
2. Concurrency: start a publish and immediately attempt a second. Pass: second refused by the lock.
3. Real publish (one only): release notes run-tagged. Pass: exit codes checked (visible in output), archives complete (spot-extract), Upgrade row + agent_dist artifact present.
4. Apply the upgrade to A, B, D via the node Updates tab. Pass per node: two-pass self-update behavior handled automatically or surfaced per the doc'd flow; final version matches; agent self-updated to the shipped version (agent release channel gate 5 evidence — record versions before/after); upgrade-package cache purged after success.
5. Stuck-apply guard: not tested live here (T8.2 covers stuck jobs generically).

### T7 — DR drill `[blocking prep: vps_c must be virgin]` — the headline scenario
Using **only**: the B2 bucket contents, the ephemeral recovery private key in the run workspace, and virgin `vps_c`. Explicitly without: touching `vps_a`, the key file on any node, or any control-plane copy of the key material other than the sealed blob.
1. Pick A's latest encrypted DB backup + sealed blob from B2 (listed via the bucket, not via A).
2. Unseal the backup key locally (`escrow_keypair.php unseal`).
3. Install a fresh run-tagged site on `vps_c` via the dashboard; write the recovered key to its key file over direct SSH (never through a job record).
4. Restore A's cloud backup onto C through the Backups-tab flow (cloud-only path: download step + engine).
5. Pass: C serves A's data (marker row present, content hash of a known page matches A's); job markers `RESTORE_OK`; report records the wall-to-wall step list so the runbook doubles as the documented DR procedure's proof.
6. Variant (paper check, no execution): confirm every input used exists outside the control plane (bucket + password manager suffices) — i.e., the drill would also succeed with dev dead.

### T8 — Monitoring, alerts, watchdog (hardening Phase 4)
1. Down/up alerts: stop apache on D. Pass: down alert email arrives at the configured recipient (verify via the dev inbound mailbox per house testing docs — send alerts to a `dev.getjoinery.com` alias). Restart apache. Pass: recovered alert with a **real duration** (P-19: not "unknown").
2. **Stuck-job watchdog (P-4):** start a long-running job on D (e.g. a backup), `systemctl stop` the agent (or kill the SSH-side process) mid-run. Pass: within the watchdog window the job flips to failed/`TIMED_OUT`, a node health problem appears, an alert email is sent; downstream pollers observe the terminal state (no eternal `running`). Restart the agent; next job runs normally.
3. SSL-state stability: drop port 443 on D for a single monitoring cycle (iptables rule, then remove). Pass: one failed probe does **not** flip `mgn_ssl_state`; sustained failure (2+ cycles) does; recovery does not re-fire rDNS side effects more than once.
4. Result-processing claim: with a job just completed, hit the status-poll endpoint concurrently (2 parallel requests via curl) while the dashboard sweep also runs. Pass: exactly one processing (welcome-email/rDNS class side effects not duplicated — for a plain backup job, assert `mjb_result` written once; the P-2 unit test covers the email case).

### T9 — SSL provisioning (SKIPPED without `dns` input)
1. Direct domains (a, d, b1): nodes reach `mgn_ssl_state='active'` via the scheduled task; HTTPS serves with a valid LE cert.
2. **Cloudflare-proxied `cf` on D (P-6):** pass: the task dispatches despite A-record pointing at Cloudflare; no certbot run; proto patch applied (`X-Forwarded-Proto` handling present); site works over the proxy with app-visible HTTPS.
3. Disabled-node exclusion: disable D briefly; task creates no `provision_ssl` job for it.
4. Backoff sanity: force one failure (temporarily block 80), confirm the retry respects the hourly backoff (timestamps in UTC math — P-15) and the give-up clock measures the failure streak, not ancient history.

### T10 — Provisioning state machines
1. Run the task-tier suites built in hardening Phase 4 (fabricated rows: happy path, timeout, CF domain, empty-pubkey refusal, >200-answer paging). These are COVERED-BY-SUITE; record the run.
2. Live order-poll paging spot check (P-5): fabricate >200 run-tagged answer rows in the store's requirement table pointed at a scratch question, run the poll task, verify a new answer beyond position 200 is seen (handled or attempted). Delete fabricated rows.
3. Live Linode customer-cloud provisioning: **OPTIONAL** — run only if the owner explicitly provides a Linode OAuth-connected test account in the kickoff prompt; otherwise SKIPPED (billing side effects).
4. `.pub` refusal (P-7): corrupt the pipeline key's `.pub` derivation input in a scratch copy and assert `ProvisioningSetup` reports failure rather than writing an empty file (unit-level; no instance cre