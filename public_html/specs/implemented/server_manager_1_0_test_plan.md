# Server Manager 1.0 — Autonomous Test Plan

**LIVE RUN COMPLETE 2026-07-24 (uncommitted).** Everything runnable without a B2 test bucket or a publish has been run live on dev + real VPS and is green: Track M (safe 57/57, db 163/163 incl. the test-db model tests), Track S (S-1/S-3/S-5), **R-1** restore engine incl. the **B-2** non-root sudo axis, **F-4** monitoring incl. real recovered-duration and broken-monitoring truthfulness, **F-5** both SSL branches (certbot + Cloudflare), and **Track C** Docker machinery (container key custody / Defect A, same-host copy_database, port allocation, relay result-processing, TCP-port monitoring). **F-2 DR DRILL PASSED 2026-07-24 (the headline 1.0 gate) — the whole custody design is proven end-to-end.** Reusing the existing prod B2 target (namespaced under the test slug) with a real small site's data: encrypted+escrowed backup → B2, then temp1 destroyed (key shredded, node deleted), the key recovered from the **B2 sealed blob + offline private key alone** (control-plane-dead variant), and restored onto a fresh box with a **byte-identical** data match. **Eight bugs were found and fixed** along the way: node_add `NOT NULL` (1.2→1.3), **P-6** Cloudflare SSL never dispatched, **P-19** recovered-duration "unknown", **P-18** container port never recorded/pinned, **P-17** the dashboard result-sweep only reconciled 3 of 11 job types, a missing BackupKeyEscrow `$test_fixture`, the agent-run **`BACKUP_KEY_FPR`** step (was empty + hashed the file not the value — now value-hashed and quote-robust), and **in-process escrow** reading an SSH transport failure as "no key" (now fails loud with a key-access hint, which also closes the orphan-escrow-row trap). plugin 1.9.0→1.9.4. A stale test-database clone (pre-existing drift, not a regression) was refreshed via the Test Database admin page. **Still gated:** only F-3 fleet upgrade + Option-C placeholder verification (need a publish, which the release provides); the "no cron trigger for result reconciliation" stays deferred (coupled to the Phase-4 claim lock). Detail below.

**Status:** COMPLETE 2026-07-24 — every track ultimately ran: the reduced no-cloud pass logged below, then Docker track C (container key custody, same-host copy, relay machinery P-17/P-20), SSL both branches (F-5), monitoring (F-4), and the headline **F-2 DR drill — PASSED** (destroy a node, recover its key from the B2 sealed blob + offline key alone, byte-identical restore onto a fresh box). Eight bugs found and fixed during the campaign; a subsequent pre-commit review fix pack hardened the findings further. The only remaining plan item, F-3 fleet upgrade, executes via the release publish itself. Original run log follows. Hardening phases 0–3 + Option C + node_detail extraction are built (uncommitted). A **reduced no-cloud run executed 2026-07-23** against 5 owner VPS (all ~1GB root, no Docker; no dedicated B2 test bucket / test DNS / Cloudflare host provided). Passed live: Track M baseline (safe 36/36, db 503/503), Track S (S-1 hostile-name inert across all 6 tabs, S-5 sort-injection, S-3 delete CSRF accept), and **R-1 restore-engine round-trips on real boxes** — box A root 10/0, box B non-root `deploy` sudoer 13/0 including the **B-2 sudo-`$HOME`-flip axis proven live** (absolute `--key-file` → `RESTORE_OK`, `$HOME`-relative → `BACKUP_KEY_MISSING`). **SSL/F-5 also passed live 2026-07-23** once DNS was set up (temp1.jeremytunnell.com direct→`.250`, temp2 Cloudflare→`.17`): full Joinery installs on both boxes, then **both SSL branches proven** — temp1 got a valid trusted Let's Encrypt cert via certbot (Apache-served, `ssl_verify=0`), temp2 took the **Cloudflare branch** (`SSL_SKIPPED_CLOUDFLARE`, proto patch; served HTTP/2 via CF edge), both `ssl_state=active`. **F-4 monitoring also passed live 2026-07-23** (temp1/temp2, via a single-node reflection harness so prod is untouched): down transition after the 2-failure debounce with `down_since` set + one alert per transition; recovered transition reporting the **real** down duration ("recovered after 2m"); and broken-monitoring truthfulness — an inconclusive api-probe records the fault and leaves `last_conclusive` stale (visible "monitoring stopped") instead of a false green. Caught + fixed **four** bugs: (1) node_add's create-path lacked the empty-TCP-port → 0 guard the edit-path has, so adding a node with uptime monitoring off `NOT NULL`-violated `mgn_uptime_tcp_port` (node_add.php 1.2→1.3); (2) **P-6** — the CF branch existed in `build_provision_ssl` but `ProvisionPendingSsl`'s host-IP DNS gate skipped every Cloudflare-proxied domain forever, so it never dispatched (fixed per spec line 202: `is_cloudflare_domain` made public + the task dispatches CF domains, A-record gate kept for non-CF; ProvisionPendingSsl 1.0→1.1, JobCommandBuilder 1.11→1.12); (3) **P-19** — the recovered-uptime alert read `down_since` after `apply_state` had already cleared it, so it always said "recovered after unknown"; fixed by capturing `down_since` before `apply_state` and passing it to `send_alert` (RunNodeUptimeChecks 1.4→1.5); and (4) reproduced-but-deferred: no cron sweep processes completed `install_node` results (only page loads do), so an install can leave a node stuck `installing` until an admin opens the dashboard — genuinely coupled to the Phase-4 result-processing claim lock (adding a sweep without it would widen the double-processing window), so left for that phase. plugin 1.9.0→1.9.1; db 503/503, safe 36/36 green throughout. **Deferred pending owner resources:** all cloud backup/restore (R-2, B-5), escrow-to-B2 (E-6), the **F-2 DR drill** (the 1.0 gate), SSL/CF (F-5), the Docker container track (C), and the publish-gated F-3/Option-C checks. Not run live on the shared dev control plane: setting `server_manager_escrow_public_key` (would make real prod nodes seal to a soon-deleted test recovery key).
**Companion:** `specs/server_manager_1_0_hardening.md` (the build package — every test here maps to a phase/finding there) and `specs/backup_key_custody_and_recovery.md` (the DR threat model this plan proves is closed).
**Runner:** an autonomous agent driving the dev control plane (`dev.getjoinery.com`) via browser MCP + `node_exec.php` + CLI, against a fleet of VPS the owner creates in advance and hands over as an IP list.

---

## 0. READ FIRST — what the owner provides up front

### 0.1 The VPS fleet: **5 VPS** (plus one optional track)

Create **five** plain Ubuntu VPS (fresh, nothing installed) and hand over their IPs. Each VPS has a fixed **role**; the agent installs everything else. Sizes are minimums — a 2 GB / 1 vCPU box is fine for all except the Docker host.

| Role | Label | Size | SSH user | Why this box exists (axes it uniquely covers) |
|---|---|---|---|---|
| **A** | `sm-test-primary` | 2 GB | `root` | Baseline. Runs the **Go agent (API transport)**. B2 cloud target, direct-DNS domain. All in-place backup/restore/escrow happy paths. **Reused at the end as the DR victim** (its key gets wiped to force escrow-only recovery). |
| **B** | `sm-test-sudo` | 2 GB | **non-root** (sudoer, e.g. `deploy`) | **SSH-only transport** (no agent). The sudo-`$HOME` axis — Defect B-2 and P-10 (unprivileged config read) manifest *only* on a non-root node. B2 cloud target. |
| **C** | `sm-test-dockerhost` | 4 GB | `root` | A **Docker host** running **2 Joinery containers** + **1 relay container**. Covers container-node key custody (Defect A blast radius), multi-site-per-host, port allocation (P-18), same-host `copy_database`, and the relay job/uptime paths (P-17, P-20, tcp_port monitoring). |
| **D** | `sm-test-cf` | 2 GB | `root` | A node whose site domain is **Cloudflare-proxied** (A-record resolves to Cloudflare, not the host). The only box that exercises P-6 (CF SSL path + proto patch) and the DNS-gate distinction. |
| **E** | `sm-test-dr-target` | 2 GB | `root` | Starts **bare** (no Joinery). The **DR replacement**: the escrowed backup of the destroyed A is restored onto E using only the B2 bucket + the recovery key. Proves the headline scenario. |

**If the owner wants to skip a track**, drop the box: no Cloudflare domain → skip D; no interest in the DR drill → skip E (but then the 1.0 bar is not met — the DR drill *is* the point of the whole custody work).

### 0.2 The optional 6th track — live cloud provisioning (no IP, needs credentials)

`ProvisionCustomerCloud` / `PollHostingOrders` create their **own** VPS via the Linode API mid-test — they don't consume a pre-supplied IP. Testing them live needs: a **Linode OAuth account connected in the provisioning setup**, a **test domain the agent can point**, and acceptance that the test **spends money** (instances are created and destroyed). Decision for the owner: run this track **live** (real Linode instances, ~1–2 created and torn down) or **mocked** (the existing `customer_cloud_provisioning_test.php` driver-mock pattern, no real spend). Default: **mocked** for the state-machine logic (P-5/P-6/P-7/P-11/P-12), one **live** end-to-end provision only if the owner opts in.

### 0.3 Prerequisites the owner sets up before handing over

1. **SSH:** the control-plane's automation public key installed in each box's `authorized_keys` (root for A/C/D/E, the sudoer for B). This is what lets node adoption work without interactive login.
2. **A dedicated B2 test bucket** (e.g. `joinery-sm-test`) with its own application key — *not* the production `joinery-backups-*` bucket. Cleanup wipes it.
3. **DNS:** a wildcard or a handful of A-records under a test domain the owner controls — `a.smtest.<domain>` → A's IP, `b.smtest...` → B, `c1/c2.smtest...` → C's containers, `e.smtest...` → E. **`cf.smtest.<domain>` proxied through Cloudflare** for box D.
4. **The fleet handoff file** (see 0.4).

### 0.4 Handoff format — `test_fleet.json`

The owner fills this and drops it at a path the agent is told; the agent reads roles → connection info from it (never hard-codes IPs):

```json
{
  "b2": { "bucket": "joinery-sm-test", "endpoint": "s3.us-west-000.backblazeb2.com",
          "access_key": "…", "secret_key_ref": "read from stdin/env, not here" },
  "domain_base": "smtest.example.com",
  "cloudflare": { "enabled": true, "proxied_host": "cf.smtest.example.com" },
  "linode_track": "mocked",
  "nodes": {
    "A": { "ip": "…", "ssh_user": "root",   "domain": "a.smtest.example.com",  "transport": "agent" },
    "B": { "ip": "…", "ssh_user": "deploy", "domain": "b.smtest.example.com",  "transport": "ssh" },
    "C": { "ip": "…", "ssh_user": "root",   "containers": ["c1.smtest.example.com","c2.smtest.example.com"], "relay": true },
    "D": { "ip": "…", "ssh_user": "root",   "domain": "cf.smtest.example.com", "cloudflare": true },
    "E": { "ip": "…", "ssh_user": "root",   "role": "dr-target" }
  }
}
```

The B2 secret and any key material are passed to the agent out-of-band (env var / stdin), **never** written into this file or into chat — the secret-handling rule holds throughout.

### 0.5 The recovery keypair (stands in for the owner's password manager)

At the start of the run the agent executes `escrow_keypair.php generate`, writes the **private** key to a mode-600 file in its scratchpad, and pastes the **public** key into the `server_manager_escrow_public_key` setting. That scratchpad private-key file *is* "the password manager" for the drill: the DR track is only allowed to read it, never the control plane. At teardown the agent shreds it.

---

## 1. How the agent runs this (execution contract)

- **Idempotent setup / reset:** every track begins with a `reset_node(role)` that returns the box to a known state (drop the test DB, clear `/backups/*`, remove `~/.joinery_backup_key`, delete the node's rows from the control plane). A track must pass on a re-run without manual cleanup.
- **Machine-checkable pass/fail:** each step asserts on the **stdout markers** the hardening spec defines (`RESTORE_OK`, `BACKUP_KEY_MISSING`, `DECRYPT_FAILED`, `ARCHIVE_CORRUPT`, `RESTORE_LOAD_FAILED`, `TIMED_OUT`, `AGENT_UPDATE_CORRUPT`, `BACKUP_KEY_FPR=…`) plus DB row-count equality and control-plane row state. No "eyeball the log" steps except the explicit browser-visual ones (XSS inertness, tab rendering).
- **Safety rail — never touch production:** the agent operates **only** on nodes whose slug starts with `sm-test-`. A hard guard: before any destructive command it asserts the target node's slug prefix and that its IP is in `test_fleet.json`. The real fleet (Server Manager dashboard's managed nodes) is off-limits.
- **Failure handling:** a failed assertion records `{track, step, expected, got, node}` and continues to the next *independent* track (so one broken phase doesn't abort the run); dependent steps within a track short-circuit. Final output is a single results table + the JSON detail.
- **Ordering:** tracks run in the sequence in §7. The DR drill is last because it consumes box A destructively.
- **Live-verify budget:** browser-MCP steps (XSS, tabs, CSRF) run against `dev.getjoinery.com`; use the Playwright lock-recovery from memory if the browser is wedged. Assets may be Cloudflare-cached — verify origin with a `?cb=` query when checking freshly-changed JS.

---

## 2. Track P0 — smoke (Phase 0 one-liners)

Fast, no VPS needed beyond adopting A. Gates the rest — if these fail the build is wrong.

| # | Asserts | Method |
|---|---|---|
| P0-1 | `EmailSender` require present; a parked provision actually sends its reconnect email | Mocked provision → assert `QueuedEmail` row created (not swallowed). |
| P0-2 | SSL card "Resolved" row shows IPs | Browser: A's node detail → SSL Setup card shows `implode`'d IPs, no PHP warning in log. |
| P0-3 | Marketplace floor is 10 | Log in as a permission-8 fixture → `/admin/server_manager/marketplace` redirects. |
| P0-4 | Marketplace reflected XSS closed | Navigate `?message=<script>alert(1)</script>` → script inert (browser console clean, no dialog). |
| P0-5 | Relay-enablement comment matches code | Static: grep the file; comment says "born enabled". (Trivial, but it's in the phase.) |

---

## 3. Track R — the single restore engine (Phase 1) — **boxes A, B, C**

The audit's silent-no-op and no-decrypt bugs survived because nothing ever executed a restore. This track executes real round-trips on every transport/user axis.

**R-1 Local shell gates (run on each of A root, B non-root, C container):**
1. Create throwaway DB with known row counts → `backup_database.sh` **encrypted** → `restore_database.sh --non-interactive --key-file … --db-user …` into a second DB → **row counts equal**, stdout `RESTORE_OK`.
2. Repeat **plaintext** (`.sql.gz`, not bare `.sql` — assert the P-9 fix: file matches the glob and is gzipped).
3. **Wrong key** → `DECRYPT_FAILED`, target DB **untouched** (row count unchanged).
4. **Truncated archive** → `ARCHIVE_CORRUPT`, target DB **untouched** (proves verify-before-drop, B-4).
5. `restore_project.sh` `verify_archive` emits **exactly one line** on stdout (the B-1 regression trap) → then a real project restore **changes** the target (drop a sentinel table first, confirm it returns).

**R-2 Dashboard round-trips (browser + job assertions):**
6. On **A** (agent transport) and **B** (SSH transport): take an **encrypted cloud backup** → confirm `.sql.gz.enc` lands in B2 → click **Restore** on the Backups tab → job reaches `RESTORE_OK`, verify-restore step shows tables. This is Defect B closed on both transports.
7. On **B specifically** (the non-root box): encrypted **project** restore succeeds — the B-2 sudo-`$HOME` fix. Assert the key is found (`no BACKUP_KEY_MISSING`) even though the restore runs under sudo.
8. **From-backup install** pointed at an existing `.enc` dump: either restores cleanly or is refused **before** the site is installed (no half-installed node), per the Phase 1.3 picker warning.
9. **B-5 cloud download:** stage a **>1 GB** archive in B2 → cloud-only restore downloads it (no 15 s timeout abort) and streams to disk (node RSS stays bounded — assert peak memory well under file size).

**R-3 Builder-string regressions:** run `job_command_builder_test.php` — restore steps contain `restore_database.sh --key-file`, contain **no** inline `DROP SCHEMA` in `build_restore_database`, contain **no** `openssl rand` anywhere.

**Pass:** every round-trip equal-or-correctly-refused on all three boxes; wrong-key/corrupt cases never mutate the target.

---

## 4. Track E — sealed-box escrow (Phase 2) — **boxes A, B, C** + recovery key

| # | Asserts | Method |
|---|---|---|
| E-1 | Empty-setting refusal | Clear `server_manager_escrow_public_key` → attempt an encrypted backup on A → refused with a clear "not escrowed" error, **no** node-side key generated, **no** `openssl rand` runs. |
| E-2 | Escrow-before-push invariant | Set the public key. Trigger `BackupKeyEscrow::ensureNodeKey(A)` with a **simulated push failure** → a `bke_` row exists but the node has no key (never the reverse). Then real run → row + node key, fingerprints match. |
| E-3 | Seal/unseal round-trip | db-tier test: seal a known key → `escrow_keypair.php unseal` with the scratchpad private key → **byte-identical** key back. |
| E-4 | Append-only rotation | Rotate A's key → **two** `bke_` rows for A, **both** blobs unseal, old archive still decryptable with the old key. |
| E-5 | Fingerprint-mismatch detection | Manually change A's `~/.joinery_backup_key` out-of-band → next backup emits a `BACKUP_KEY_FPR` that mismatches → `JobResultProcessor` records a health problem. |
| E-6 | Container key durability | On **C**: escrow a container node's key → confirm the sealed blob exists in the DB **and** replicated to B2 (`escrow/{slug}/{fpr}.sealed`) → the key is recoverable with the container gone. |
| E-7 | Inventory flag | A node with a cloud target and **no** `bke_` row shows "Backup key not escrowed" on the dashboard; escrowing clears it. |
| E-8 | Migration of existing key | Pre-seed A with a legacy `~/.joinery_backup_key`, no row → run "Escrow existing key" → row created (`bke_source='migrated'`), and assert the key **never appears** in any `mjb_commands`/job-output row (read over SSH in-process, not via a job). |
| E-9 | Agent signing key escrow | Run the one-time agent-signing-key escrow → `bke_` row `kind='agent_signing'`, `mgn_id` NULL, blob unseals to the signing key; the chmod-window and `catch(Throwable)` fixes (P-21) verified by inspection + a forced-error test. |

**Pass:** every node with a target is escrowed, no unescrowed flags, and E-3/E-6 prove recoverability independent of the node.

---

## 5. Track S — admin-surface security (Phase 3) — browser on `dev`

| # | Asserts |
|---|---|
| S-1 | **Hostile-node-name sweep:** adopt a node named `x',alert(1),'<img src=x onerror=alert(1)>` → open **every** tab (overview, backups, database, updates, jobs, api keys) and the Updates confirm → **no** dialog, console clean, name renders as literal text (S-2/S-3 closed). |
| S-2 | **Discovery XSS:** point "Detect" at a box returning a hostile container name / hostname / error → results render inert; the onclick payloads are `data-`-attribute lookups, not serialized objects. |
| S-3 | **CSRF enforced:** POST to each state-changing handler (node delete, target delete/test, job cancel/**rerun**, publish delete) **without** a token → rejected; **with** token → accepted. Assert the old GET action links are gone (a GET to the former `?action=delete` URL does **not** mutate). |
| S-4 | **Credential no-wipe:** edit an **S3** and a **Linode** target, change only "Enabled", save **without** re-typing the secret → stored secret **unchanged**, uploads still work. Confirm **no** secret is present in the page HTML for any provider (B2 included). |
| S-5 | **Sort-column whitelist:** `?sort=$(injection)` on jobs and node-detail → rejected/ignored, no SQL error. |
| S-6 | **Secrets not persisted:** create a backup+upload job → its `mjb_commands` row contains **no** cloud secret material; pipeline API secret is SecretBox-encrypted at rest in settings. |

**Pass:** clean browser console on every hostile input; no unauthenticated mutation; no secret in HTML or job rows.

---

## 6. Track J — pipeline majors (Phase 4) — **boxes A, C** + mocked provisions

| # | Asserts | Method |
|---|---|---|
| J-1 | **Result-processing claim lock** | Fire the sweep, the status-poll endpoint, and a task tick **concurrently** at one just-completed `install_node` job → welcome email sent **once**, rDNS attempted **once**, WG peer exec **once** (P-2). |
| J-2 | **Failed-job result recorded** | Force a backup job to fail (bad path) → `mjb_result` written (not NULL) → poll it 3× → processor runs **once**, not every 2 s (P-16). |
| J-3 | **Publish integrity** | Publish with an **artificially failing tar** (chmod a source dir unreadable mid-build) → publish **aborts**, **no** version claimed in `system_version`/Upgrade row, lockfile released (P-3). Then a clean publish succeeds and the fleet upgrades. |
| J-4 | **Stuck-job watchdog** | Create a job, kill the agent so it never completes → after the per-type timeout the watchdog marks it `TIMED_OUT`, surfaces a health problem + alert, and the downstream provision moves to a terminal state instead of hanging (P-4). |
| J-5 | **Order-poll paging** | Seed **>200** historical domain answers + one new paid order → poll provisions the new order (not silently dropped); scanned/handled counts logged (P-5). |
| J-6 | **Unwatched relay job** | Complete a `provision_relay` job on C's relay with no browser open → the sweep (now deriving its type list from `JobResultProcessor`) processes it: relay row + WG pubkey registered (P-17). |

---

## 7. Track F — the big end-to-end flows (the 1.0 bar)

These are the flows the whole plugin exists for. They run **after** the unit-ish tracks pass.

### F-1 — Full node lifecycle (box A, then C container)
Adopt/install → first encrypted backup (auto-escrow fires) → in-place restore → schema change on the node → project backup (DB+files+apache) → project restore onto the same box → uptime monitoring goes green → SSL provisioned. Every step green, escrow row present throughout.

### F-2 — **The DR drill (headline — box A destroyed → box E)**
This is the scenario from `backup_key_custody_and_recovery.md` that motivated everything. Sequence:
1. On **A**: confirm encrypted backups exist in B2 and A has a `bke_` row (from F-1).
2. **Simulate total loss:** wipe `~/.joinery_backup_key` on A **and** delete the node from the control plane — the key now exists **only** as the sealed blob. (Optionally power A off; the drill must not touch it again.)
3. Acting as the operator with **only** (a) the B2 bucket and (b) the scratchpad recovery private key — **no** access to A: fetch `escrow/{A-slug}/{fpr}.sealed` from B2 → `escrow_keypair.php unseal` → recover the key.
4. Provision **E** as a fresh node, write the recovered key, restore A's latest encrypted DB backup from B2 through the dashboard onto E.
5. **Assert row-count / content equality** between what A had (snapshot taken in F-1) and what E now serves.
6. **Control-plane-dead variant:** repeat step 3 sourcing the blob from B2 only (not the DB) to prove recovery survives losing the control plane too.

**This is the pass/fail gate for the entire custody effort.** If F-2 fails, 1.0 is not met.

### F-3 — Publish → fleet upgrade
Clean publish (after J-3's failure case) → apply the upgrade to A, B, and both C containers via the Updates tab → all report the new version, agent self-updates where applicable (`AGENT_UPDATE_CORRUPT` **not** present) → two-pass upgrade gotcha handled (second run deploys) per memory.

### F-4 — Monitoring & alerting truthfulness
Take a node down (stop its web server) → uptime flips to down, `down_since` set → alert fires → bring it back → "recovered after **<real duration>**" (not "unknown", P-19) → break monitoring itself (bad check URL) → the node shows "monitoring stopped working," not a false green (the `last_conclusive` distinction). Relay tcp_port check on C's relay proves alive by TCP, not HTTP.

### F-5 — Cloudflare SSL path (box D)
D's CF-proxied domain: SSL provisioning dispatches (P-6 — no longer stuck in `pending` forever), takes the CF branch (no certbot, proto patch applied), and the app sees HTTPS. Direct-DNS node (A) still takes the certbot branch. Both end `active`.

---

## 8. Track M — minors & refactors (Phase 5–7) — opportunistic

Run the full `php tests/run.php safe` and `db` (must stay green), plus the **new** task-tier state-machine tests (Phase 4/R-4) and shell gates (Phase 1.6). Spot-checks for the minors that have observable behavior: recovered-duration (F-4 covers P-19), `node_exec` container piping (P-22 — `php x | grep y` runs *inside* the container), port actually passed to install.sh (P-18 — installed container's published port equals `mgn_port`), UTC `strtotime` (P-15 — flip PHP tz on a scratch run, backoff unchanged). Refactor tracks (R-3/R-4/R-6/R-7) are covered by the builder-string suite staying green through the extraction — the suite *is* the regression harness.

---

## 9. VPS-to-track matrix & sequencing

| Track | A | B | C | D | E | Notes |
|---|---|---|---|---|---|---|
| P0 smoke | ✓ | | | | | adopt A only |
| R restore | ✓ | ✓ | ✓ | | | all transports/users |
| E escrow | ✓ | ✓ | ✓ | | | + recovery key |
| S security | browser on dev; adopts a throwaway hostile-named node (can be a C container) |
| J pipeline | ✓ | | ✓ | | | + mocked provisions |
| F-1 lifecycle | ✓ | | ✓ | | | |
| **F-2 DR drill** | ✓→destroyed | | | | ✓ | **runs last**, consumes A |
| F-3 upgrade | ✓ | ✓ | ✓ | | | before F-2 destroys A |
| F-4 monitoring | ✓ | | ✓(relay) | | | |
| F-5 CF SSL | | | | ✓ | | |

**Serial order:** P0 → R → E → S → J → F-1 → F-3 → F-4 → F-5 → **F-2 (destroys A) last**. Everything using A finishes before the DR drill wipes it.

---

## 10. Teardown & cost

At the end: agent deletes all `sm-test-*` nodes/hosts/targets from the control plane, empties the B2 test bucket, shreds the scratchpad recovery private key and clears the escrow public-key setting, and (if the live Linode track ran) destroys any instances it created. The owner then destroys the 5 VPS. **Cost note:** 5 small VPS for the run's duration + a near-empty B2 bucket; the live-provisioning track (if opted in) adds 1–2 short-lived Linode instances. No human-time estimate given — the agent runs it.

---

## 11. Coverage back-reference

Every finding in `server_manager_1_0_hardening.md` is exercised: Phase 0 → P0; Phase 1 (B-1..B-5, P-8/9/10/23) → R; Phase 2 (Defect A, escrow, agent key) → E + F-2; Phase 3 (S-1..S-8, U-1) → S + P0-2; Phase 4 (P-2..P-7, P-11/12/16/17) → J + F; Phase 5 minors → M + F-4; Phase 6/7 refactors → builder-suite green through M. The one thing only a **live** run proves and no unit test can: **F-2**, restoring a destroyed node from B2 + an offline key. That drill is the 1.0 sign-off.
```
