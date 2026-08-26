# Agent on Every Node — Server Manager's Transport Migration

**Status:** **READY FOR BUILD — 2026-08-25, unbuilt.** Owner call: Server Manager's control-plane-holds-SSH model has the same skeleton-key vulnerability the sentinel spec's §13.O9 rejected, and must be fully migrated **before** the sentinel spec (`specs/sentinel_managed_recovery.md`) is built. The target: **the Go agent runs on every managed node, capable of exactly what is precompiled into it and nothing else**; the control plane holds no credential that can run arbitrary code on a node. All five decisions were resolved with the owner the same day (§7), each at the strict pole: **no `exec` class anywhere, no unattended destructive anywhere, no bespoke copy job, no plane-supplied encryption keys, and no standing sshd** (A4/A5 — with the §3.5 exfiltration rules, §3.6 code-integrity attestation, and the §3.7 promise boundary closing the read side to the same standard as the write side).

**Ordering (owner-set):** this migration completes first; the sentinel spec consumes the result (its §6 "vocabulary channel" and §14.N *are* this architecture).

---

## 1. The problem, stated against the current code

Today the trust model is: **the control plane is root everywhere, unattended.**

- Every managed node is reached over SSH with a key named by `mgn_ssh_key_path` — in practice **one shared provisioning keypair**, stored as a plaintext path on the control plane's disk. Compromise the plane, own the fleet.
- The job queue's unit of work is a **shell command string**. `ManagementJob::createJob()` stores `mjb_commands` as a JSON list of steps; the Go agent is a *generic step executor* — it runs whatever the queue says. The security boundary is entirely plane-side, which is to say: for a compromised plane, there is none.
- `build_run_command` / the node Console tab / `node_exec.php` are the honest expression of the model: arbitrary command, any node, on demand. (To its credit, every execution is recorded as a job and redacted — but recording is accountability, not containment.)
- Every node must expose **inbound SSH** to the plane. Nodes behind Cloudflare or NAT fight this; the beta-tester nodes already prefer pull.

The sentinel spec refused to extend this shape to customer machines (§13.O9: no credential on the plane that can act destructively unattended). The same argument applies to the fleet that exists today — the only difference is that today's blast radius is our own machines. This spec closes the gap platform-wide, so Server Manager has one architecture instead of a secure customer edition and an insecure home edition.

**What this migration deliberately deletes:** the ability of a control plane to run an instruction it composed at runtime on a managed node. That generality is the vulnerability. Every operation the fleet actually performs becomes a **named primitive with validated, bounded parameters**, compiled into the signed agent.

---

## 2. What already exists (verified 2026-08-25)

The migration is far cheaper than it looks, because the hard distribution problems are already solved:

- **The agent and its release channel are built and shipping.** Go source at `/home/user1/joinery-agent/` (setting `server_manager_agent_source_path`). Publishing cross-compiles amd64/arm64, signs with Ed25519 (`config/agent_signing_key`), and bundles into `plugins/server_manager/agent_dist/` (`manifest.json` with sha256 + signature per artifact). The agent **self-updates between jobs**: verifies the signature against the public key baked into its own binary, keeps a `.bak`, rolls back on a failed start, records rejections. *"The site tree is writable by the web user while the agent runs as root, so the agent never installs anything the publisher did not sign"* — the exact discipline this spec generalizes.
- **The agent is already installed at every root moment.** The plugin's `host_installer` (`provisioning/install_agent.sh`) runs at site install, code upgrade, container start, and on demand — binary, env file, systemd unit or cron supervision. Wherever the plugin is present, distribution is done; **the rollout cost is configuration, not deployment.**
- **The job queue and lifecycle exist** (`mjb_management_jobs`: commands, parameters, result, step progress, concurrency guard on `pending`/`running`).
- **A per-node API transport already exists.** `mgn_api_public_key` / `mgn_api_secret_key` / `mgn_site_url` authenticate the plane to a node's `/api/v1/management/*` (web-stack, app-level). `JobCommandBuilder::transports_for()` routes per operation: `build_<op>_api` vs `build_<op>_ssh`, health-probed at build time. So the codebase already thinks in transports per operation — this spec adds a third and makes it primary.
- **Heartbeats exist** (`ahb_agent_heartbeats`, with `ahb_bundled_version`, `ahb_update_state`, surfaced on the dashboard).
- **The signing key (custody verified 2026-08-25):** one Ed25519 keypair, minted automatically on first publish (`AgentDistPublisher::ensureKeys()`, zero-config, same pattern as the provisioning key) at `{site root}/config/agent_signing_key`; the verification key is **baked into every agent binary at compile time** — verification makes no live call anywhere. Its offsite copy is deliberately the publishing site's own encrypted project backup (openable with the recovery key; no separate escrow record), and `NodeMonitorHealth` raises "fleet trust root not yet backed up" until a successful offsite project backup exists. **No rotation flow exists**: agents trust only their baked-in key, so rotating means shipping a transitional agent — signed by the old key, trusting the new — and no such machinery is built. Stated, not scheduled; built only if ever needed.

What does **not** exist: an agent that takes work from anywhere but its own local database; any notion of a job that is *not* a shell string; any node-side refusal — **and no signed tree.** The Ed25519 signature covers only the agent bundle (`agent_dist/manifest.json`, per-arch sha256 + signature). Platform and plugin upgrade archives are signed nowhere, `utils/upgrade.php` verifies no signature on apply, and publish's sha256 tree hashes exist solely for version-bump bookkeeping. The signed per-file tree manifest that §3.2 and §3.6 depend on is **new publish-time work** (component G).

---

## 3. Target architecture

### 3.1 One binary, one new job source, one new job kind

The agent keeps its control-plane duties (plane-local jobs like `publish_upgrade` read from the local DB as today). It gains:

- **A remote job source.** In node posture the agent polls its management node **outbound over HTTPS**, signing every request with a per-node **Ed25519 identity the node itself generates at enrollment** — the management node stores only the public key; no credential that authenticates as the node ever exists off the node (enrollment itself shares no secret either — Phase 1.5). Claim, execute, post results — the same lifecycle `mjb_management_jobs` already models, exposed through plane API endpoints. Polling gives the plane agent-liveness for free (last-poll time = the heartbeat).
- **Primitive jobs.** A job addressed to a node agent is `{primitive_name, params}` — never a command string. The agent validates the name against its **compiled-in vocabulary** and the params against that primitive's declared bounds, and refuses anything else *on the node*, whatever the plane says. `mjb_commands` keeps carrying the payload (a `{primitive, params}` object instead of shell steps); the queue, progress, and result plumbing stay.

### 3.2 Primitives: the agent never executes an instruction from the wire

A primitive is agent code selected by name. Two implementation styles, chosen per primitive:

1. **Embedded** — the logic lives in Go (service restarts, forced reboots, sysctl arming, disk reclaim, status collection).
2. **Script-invoking** — the primitive execs a platform script that already exists on the node (`backup_project.sh`, `utils/upgrade.php`, restore scripts) with a fixed argv template and validated params. **Requirement:** before running any web-tree or site-root script as root, the agent verifies the file's hash against the **signed release manifest** (the per-file tree manifest is new publish-time work — §2, component G; today only the agent bundle is signed) — the web user must not be able to swap the script under the root agent. A script that fails verification is refused and reported, same posture as the self-update check.

Primitives are grouped into **classes** — `observe` (collectors, status, list), `operate` (restarts, reboot, disk, certs, upgrade-apply, backup-run), `destructive` (restore_database, restore_chain, decommission) — because acceptance policy is set per class, not per primitive. **There is deliberately no `exec` class: arbitrary command execution does not exist in the vocabulary at all (A1).**

### 3.3 Node-side acceptance policy

Each node carries a local policy: **which primitive classes it accepts unattended, and from which paired plane.** The policy lives on the node (agent config, root-owned), so a compromised plane cannot relax it.

**The shipped policy is uniform across the entire fleet (A1/A2): `observe` ✓, `operate` ✓, `destructive` refused unattended — everywhere, own nodes included.** The human-present destructive path is **node-verified approval** (sentinel §13.O10): at pairing, the approving human's passkey public key is stored root-owned on the node; a `destructive` job executes only when it carries a signature — over the node-issued challenge binding that specific job — that the agent verifies itself. The plane relays approvals; it never gates them, so a compromised plane that captures every credential the plane ever sees still opens no destructive door. On the operator's own fleet the approving human is the operator, same mechanism. Until the approval flow exists, destructive work is the operator's personal SSH. The per-node policy mechanism still exists — it is what makes refusal node-enforced rather than plane-promised — but v1 ships one policy, with no privileged home fleet.

### 3.4 What stays

- **`RunNodeUptimeChecks`** — plane-side HTTP probing, no node involvement; untouched.
- **The management API** (`/api/v1/management/*`) — stays for interactive, app-level reads (health probes, status for the UI). It is web-stack and non-root; it was never the problem.
- **sshd on nodes** — stays *installed* for humans, but **stopped by default once the agent runs (A5)**. A human opens a **self-closing window** through the agent (`ssh_window_open`, gated by the O10 approval signature; a standalone node's own admin opens it locally; closes on timer and on reboot) for investigations and the rung 6–7 ceremonies. The migration removes the **machine-held** key regardless: the node's `authorized_keys` entry for the plane and the plane's private key. If the agent itself is dead, the provider rescue boot is the floor — which is why hardware with no rescue path (home/colo) keeps sshd running, stated at install.

### 3.5 Exfiltration rules — what a compromised plane can read (owner-reviewed 2026-08-25)

The write side is closed by the vocabulary; these rules close the read side to the same standard. The stated bar: a compromised plane must not be able to read node content — emails above all.

1. **Encryption keys are node-pinned. The plane never supplies one — this removes a live feature (A4).** Today's manager-profile fleet backup hands the node a recovery public key *per run* (`build_backup_run` passes `recovery_public_key`; `docs/backups.md`: "the control plane's, supplied per run"). Since sealing to a public key always appears to succeed, a compromised plane could silently re-seal every node's next backup — the full database, all mail — to an attacker key. Removed: the backup primitive encrypts only to the node's **own proven `backup_recovery_public_key`**, read locally; a job carrying key material is refused as out-of-vocabulary like any other wire-supplied instruction. A node with **no proven key refuses the backup primitive loudly** ("never silently downgrade" is already backup doctrine) and the plane dashboard says exactly why. **Accepted cost:** the plane loses one-key-opens-any-node recovery convenience — recovering a node's backup now requires *that node's* recovery key, which is the entire point. **Transition (owner-set): no age-out machinery.** Exactly one node needs backup continuity — **jeremytunnell** — and its cutover is ordered, not coded: start a fresh chain under its own proven key, confirm the new chain opens (the restore check), *then* delete the plane-key chain, so the node is never without an openable backup. Every other node's plane-key backups are simply **deleted**, with fresh chains set up under node keys where the node warrants backups at all — several don't, and for those the loud no-proven-key refusal is not an error state but the honest dashboard reading. This rule is orthogonal to the transport and may land ahead of it — it protects the current fleet immediately.
2. **Redaction runs on the node, before results leave.** The agent's result path strips secrets (`SmSecretRedactor` pattern) and personal data before posting. Plane-side redaction (before a model) remains, but it protects the model — the boundary is the node. This is writable only because collector output shapes are known (rule 3).
3. **Collectors are an enumerated source list, and mail is not on it.** No arbitrary-file-read collector, no SQL-query collector, ever; mail tables, mail stores, and mail content logs are excluded from every collector's sources. A needed-but-missing collector is a library-gap signal answered with a new named collector in the next release — never with a generic escape.
4. **The management API stays status-only.** It is app-level PHP that could quietly grow a content-bearing endpoint; a gate test enumerates its endpoints the way `server_initiated_write` callers are pinned, and fails on a new one.
5. **Release signing never lives on an internet-facing plane.** The honest residual: whoever holds the signing keys can ship code that reads anything, on every updating node. No architecture fixes vendor trust; what this one guarantees is the line's position — **a compromised plane is contained; only a compromised publisher is game over** — and publishing can stay on one non-exposed box while planes are internet-facing and many. **Current state fails this rule (verified 2026-08-25), and the migration must fix it:** the key lives on the internet-facing dev box (it serves dev.getjoinery.com), and the key file was found group-readable by the web user (0640 `user1:www-data` — `ensureKeys()` mints 0600; something later loosened it), so on this one machine a web-tier compromise *is* a publisher compromise: the §3.7 game-over row reachable through the contained row. Two actions, both on the Phase 0 checklist: restore 0600 immediately (nothing in the publish path needs group read — publishing runs at the CLI, and the health check only calls `is_file()`, which needs no read permission), and decide deliberately whether publishing moves to a non-exposed box or stays here knowingly.
6. **Reads are loud.** Collector invocations are recorded jobs with output size caps (the control-channel cap pattern); abnormal collector volume against a node is itself an alert.
7. **Backups must survive the plane, not just resist it.** Sealing closed *reading*; it did nothing for *deletion* — the plane holds the storage credentials, and with plane-provisioned storage it owns the bucket, so a compromised plane could delete every backup it manages before wrecking nodes. Every managed target therefore uses **storage-side immutability** (object-lock/retention: the provider refuses to delete an object younger than the retention window, regardless of what credential asks), with the lock window aligned to the backup retention schedule so normal pruning happens only after locks expire. The fleet's never-live-tested delete-refusal proof becomes a **standing scheduled check** — attempt a forbidden delete, assert the refusal, record the result on the coverage panel.
8. **The reverse direction is hostile too.** A compromised *node* is the likeliest breach anywhere in this system, and it holds a pairing credential. Two consequences: the plane's claim/result endpoints parse attacker-controllable input and are hardened accordingly (schema-validated, size-capped — the control-channel cap pattern); and collector output is **untrusted content all the way into the AI** — log lines are a prompt-injection channel into rung selection. The containment is structural and is stated where the AI lives (sentinel §14.F): the model can only choose enumerated menu items within that node's consent tier, so an injected instruction's worst case is a poor rung choice on the attacker's own already-compromised node — it cannot name a new action, reach another node, or exceed the tier.

What still leaks, stated honestly: operational metadata (uptime, versions, domains, service states) and redacted log excerpts — a novel PII format the redactor misses is the residual risk, and redactor quality is the permanent knob.

### 3.6 Code integrity attestation (owner-directed, 2026-08-25)

Everything above assumes the code serving an unlock page is the code the publisher shipped. The Sealed Vault's "designed for a compromised box" property is precisely scoped: sealing protects data at rest and against passive compromise, but an attacker who can **modify served code** waits for the next unlock and captures the credential — the universal limit of web-delivered crypto. Tamper *detection* is therefore a platform obligation, and the agent — root-owned, outside the web trust domain, already required to verify scripts against the signed release manifest before executing them (§3.2) — is the right vantage:

- **On a minutes schedule, verify the installed tree against the signed per-file manifest of the installed release.** The manifest itself is new publish-time work — today the pipeline signs only the agent bundle (§2), so component G adds per-file tree signing to publish, under the same key. The verification key is already baked into the agent binary — no live call to any distribution server, so there is nothing to intercept and the check works offline; forging it means forging Ed25519, not spoofing a host.
- **Two-sided.** Every manifest file must match its hash, **and no unexpected executable file may exist in code directories** — a dropped webshell is a new file, not a modified one, and alarms just as loudly. Site-local mutable paths (config/, uploads, data stores) are excluded from hash-matching but swept for executable plants.
- **Silence is an alarm.** Attestation status rides the heartbeat; a node that stops reporting integrity is treated as failing it. An enrolled node alerts its owner through the plane's paths; a standalone node surfaces the failure in its own admin and by direct mail, best effort.
- **Honest limits.** This reliably catches web-user-level tampering — the overwhelmingly common compromise class, which can write the web-writable tree but cannot touch the root-owned agent or fake its reports. A full-root attacker on the node can silence or falsify attestation: root on a node remains game over *for that node*. And file hashes cannot see database-sourced injection, so **vault-unlock surfaces are static-code-only**: no DB-sourced markup or script (custom HTML blocks included) on any page that collects an unlock credential, under a strict CSP — that rule lands in core with the vault, and the attestation covers the files that render those pages.

What this buys platform-wide: every Joinery install carries the agent (§2), so every install — sentinel-guarded or standalone — gets tamper-evident serving. The compromised-box story becomes exact: **sealed at rest, unconditionally; active code tampering detected on a minutes clock**, bounding credential-capture exposure to unlocks that happen inside the detection window.

### 3.7 The promise boundary — accepted limits (owner-set, 2026-08-25)

Every defense above moved verification into a domain the attacker doesn't already control: sealing into crypto, approvals into the node + the customer's device, attestation into the root agent + a baked-in signing key. A check that stays in the attacked domain is a ring, not a layer — served code cannot attest itself, which is why "the unlock page verifies the manifest" is rejected here and must not be built later. This is the floor; what remains is written down rather than patched over:

| Attacker fully controls… | They get | They don't get |
|---|---|---|
| **The plane** | Metadata, redacted log excerpts; can queue non-destructive vocabulary jobs | Mail; backups (sealed, and object-lock blocks deletion); any destructive action (approvals verify on the node); any credential worth stealing |
| **A node's web tier** | That one site's unsealed data | Sealed content; the agent and approval keys (root-owned); persistence — attestation flags the tamper in minutes |
| **A node's root** | That node entirely — its data, its own backup chains, future unlocks made on it | Any other node; deletion of its off-site backups inside the object-lock window |
| **The release-signing key** | Everything, over one update cycle | Nothing structural — this is the root of trust; key custody discipline (§3.5.5) is the only defense |
| **The customer's device / authenticator** | That customer's approvals and unlocks | Any other customer |

These residual rows are the same ones every serious security product carries; stating them is the platform's "verify, don't trust" register applied to itself, and the customer-facing copy derives from this table rather than rounding it up to "unhackable."

**The rule that keeps this from becoming a circle: a future security mechanism earns its complexity only by eliminating a row or shrinking a cell in this table — never by shuffling trust between rows.**

The case-by-case walk of this table — every operational failure and every security incident, with its response and whether it is ultimately fixable — is `specs/sentinel_managed_recovery.md` Appendix A, maintained alongside the rung library.

---

## 4. Operation inventory and disposition

Every `JobCommandBuilder` builder, and where it lands. This table is the migration's work list; a node's SSH key is removable when every operation that node actually uses has crossed.

| Operation | Today | Target |
|---|---|---|
| `check_status` | api + ssh | `observe` primitive (api transport also stays for UI) |
| `list_backups` | api + ssh | `observe` primitive / api |
| `backup_database`, `backup_project`, `backup_run` | ssh; **plane supplies the recovery public key per run** | `operate` primitives (script-invoking, manifest-verified) — **seal only to the node-pinned proven key; wire-supplied keys refused (§3.5.1, A4)** |
| `upload_backup`, `delete_backup` | ssh | `operate` primitives |
| `apply_update`, `run_plugin_installers` | ssh | `operate` primitives (archives verified against the §3.6 signed tree manifest — unsigned today; component G closes this) |
| `provision_ssl` | ssh | `operate` primitive |
| `restore_database`, `restore_project`, `restore_chain` | ssh | `destructive` primitives — in the vocabulary, but dispatched only through a human-present channel (A2) |
| `decommission_node` | ssh | `destructive` primitive — human-present (A2) |
| `copy_database`, `copy_database_by_name` | ssh (node↔node) | **retired (A3)** — composed as backup-on-source (`operate`) + restore-on-target (`destructive`, human-present) through the backup target; no bespoke primitives, no node ever trusts another node |
| `run_command` (Console, `node_exec.php`) | ssh | **retired (A1)** — the Console tab and `node_exec.php` end with the migration; investigations use the operator's personal SSH key |
| `install_node` | ssh onto a fresh VPS | **stays SSH, provisioning-time only** (§5) |
| *(new)* `ssh_window_open` / `ssh_window_close` | — | approval-gated (O10 signature) door control: starts/stops sshd for a bounded window; keys are still required to enter (A5) |
| `publish_upgrade`, `discover_nodes` | plane-local | unchanged (local job source) |

New primitives arriving with the sentinel spec (service restarts, forced reboots, self-recovery arming, evidence collectors, restore check) slot into the same vocabulary — that is the point of doing this first.

---

## 5. Bootstrap: the one legitimate SSH moment

A fresh VPS has no agent, no platform, no trust. Provisioning keeps SSH **at birth only**:

- **Provisioned installs** (`install_node`, Linode StackScript): the provisioning key is used to install the platform + agent, the node initiates its join during install and the management node auto-approves the fingerprint its own install session just watched being generated (Phase 1.5), and the final install step **removes the key's `authorized_keys` entry and stops sshd** (A5 — unless the host has no rescue path). Nothing standing survives birth.
- **Self-installs** (customer runs the installer): the installer already runs on-box as root; it installs the agent and initiates the join (the sentinel spec's §12.2 enrollment is this flow's customer-facing form). No plane SSH ever exists.

---

## 6. Migration plan

**Phase 0 — inventory.** Per node: agent present and self-updating? (heartbeats are currently local to each site's own DB — verify per node once, then node-posture polling makes liveness centrally visible). Which operations has this node actually run (query `mjb_management_jobs` history)? Does the node need backups at all, and if so does it have a **proven recovery key of its own** (§3.5.1 — set and proven as a Phase 0 task; jeremytunnell's ordered cutover — new chain proven, then old chain deleted — is the one continuity item, and every other node's plane-key backups are deleted)? Output: per-node checklist. Phase 0 also carries the two publisher-box items of §3.5.5: signing-key file mode restored to 0600, and the deliberate move-or-stay decision on where publishing lives.

**Phase 1 — the channel.** Agent: remote job source, enrollment, primitive dispatch + validation, acceptance policy, manifest verification for script-invoking primitives. Plane: claim/result API endpoints, job routing (primitive jobs to enrolled agents; SSH otherwise). Ships as an ordinary signed agent release — the fleet self-updates into capability.

**Phase 1.5 — enrollment: node-initiated join. Self-contained; no shared secret, no shell.** Connecting a running node to its management node must work from two browser tabs — no SSH (A5 turns sshd off), no file edits, and **no secret copied by a human**, because a plane-minted join token is a custody chain (plane display → clipboard → node) and a miniature of the exact pattern this migration exists to kill: a credential that exists anywhere other than where it is used.

- **Node side.** A core admin panel on the node (core, not the server_manager plugin — managed nodes don't run it; precedent is the recovery-key panel on the node's own Backups page): **"Connect to a management node."** The admin enters only the management node's URL — not a secret. The node's **root agent** generates its Ed25519 keypair (the web tier never holds any credential, one-time or otherwise) and sends a join request: claimed name + public key. The panel then shows the key's **short fingerprint** and "waiting for approval".
- **Management-node side.** The join endpoint (hardened and rate-limited like claim/result; requests auto-expire after an hour, superadmin-only to see) records a **pending join request**: fingerprint, claimed name, source IP, received time. The node's detail page shows it with an approve/reject control that displays the fingerprint and says to approve **only if it matches what the node's own panel shows**. Approval binds the public key to the node record; the agent's join-status poll picks it up and both panels flip to Connected.
- **The trust anchor is fingerprint comparison, not secret custody** (the SSH/Signal safety-number pattern). Honest statement of the trade: anyone who can reach the join endpoint can *claim* to be any node, and the only gate is the human actually comparing fingerprints before approving. In exchange, the whole secret-handling surface disappears: nothing to steal, no TTL race, no clipboard, no web-tier custody, no moment where a displayed token could pair as the node. A wrong approval is stamped visibly and severed by disconnecting the agent; destructive work is separately gated by node-verified approvals regardless (A2).
- **Retired by this phase:** the Phase-1 pairing token — its issuance action, the token-hash + expiry columns, the shown-once banner, and the env-file instruction. One enrollment story; the agent keeps reading `JOINERY_PLANE_URL`/`JOINERY_PAIRING_TOKEN` from its env only long enough to not strand a mid-flight pairing, then that path is deleted.
- **Provisioned installs auto-approve** (§5): the installer initiates the join in-session and the management node approves the fingerprint it just watched being generated — same mechanism, zero prompts. Sentinel §12 customer enrollment is this flow plus account binding.
- **Naming:** every user-facing surface says **management node** ("Connect to a management node", "Connected to …") — never "control plane".

**Phase 2 — primitives, in dependency order.** `observe` first (proves the channel harmlessly), then `operate` (backups and upgrades are the bulk of real traffic — `FleetBackupRun` switches to queueing primitive jobs), then `destructive` (built into the vocabulary and proven, though dispatch is human-present only — A2). Each primitive removes its `build_<op>_ssh` path once proven.

**Phase 3 — per-node cutover.** When a node's Phase-0 checklist is fully primitive-covered and its agent has run them live: remove the plane's `authorized_keys` entry on the node, mark `mgn_ssh_key_path` unused for that node. **The shared provisioning private key is destroyed when the last node crosses — that date, not the rollout date, is when the vulnerability closes.**

**Gate:** a test in the deploy tier asserting no job was dispatched over SSH to any cut-over node, and that no node accepts a primitive outside its policy — the migration's version of the `server_initiated_write` caller pin.

---

## 7. Decisions — all resolved with the owner, 2026-08-25

- **A1. The `exec` class — RESOLVED: retired everywhere.** No node accepts an arbitrary command from any plane, with zero exceptions — the migration's central claim holds unqualified. Accepted costs: the node Console tab and `node_exec.php` end with the migration, and production investigations move to the operator's personal SSH key, giving up the recorded-console property (`run_command` executions today are job-recorded and redacted; personal SSH leaves no such trail).
- **A2. Unattended destructive — RESOLVED: no nodes, own fleet included.** The `destructive` class is built and proven in the vocabulary but never dispatched unattended; the only destructive path is human-present — **node-verified signed approval on the vocabulary channel** (§3.3, sentinel §13.O10; same mechanism for customers and operator alike), with the operator's personal SSH as the path until that flow exists. Production destructive events are rare and incident-driven, so the operational cost is small. Consequence for the sentinel spec: its build order moves the sealed-key channel (component J) **ahead of** the rungs 3–5 proving step, and the ladder's destructive rungs are proven owner-present — which is exactly how customers will run them in service posture.
- **A3. Node-to-node database copy — RESOLVED: retired as a first-class operation.** A copy is a composition of primitives that already exist: backup-on-source (`operate`, unattended) + restore-on-target (`destructive`, human-present per A2), through the backup target. No new primitives, no node ever holds a credential to another node; the plane UI may chain the two steps with the operator present for the import.
- **A4. Plane-supplied encryption keys — RESOLVED (owner-directed, 2026-08-25): removed cleanly.** The manager backup profile's per-run recovery key (§3.5.1) was a convenience choice — one plane key opens any node's backup — that doubles as a silent full-exfiltration vector: a compromised plane re-seals the fleet's backups, mail included, to an attacker key, and nothing looks wrong. Every node seals to its own proven key; the plane never supplies key material to any primitive, for any purpose; a node without a proven key refuses to back up loudly rather than accept one. The convenience is given up knowingly: recovering a node's backup requires that node's recovery key. This removal is orthogonal to the transport migration and may ship first — it protects the current fleet immediately. No graceful age-out is built: **jeremytunnell** is the only node whose backup continuity matters, and it cuts over by proving a fresh node-key chain before its plane-key chain is deleted; every other node's old backups are deleted outright and re-created fresh where warranted (§3.5.1).
- **A5. Interactive logins — RESOLVED (owner-directed, 2026-08-25): sshd is off by default once the agent runs.** Post-migration, a standing sshd is a risky luxury — open for years, needed hours a year, nothing routine touching it. Shape: sshd stays installed but stopped; a human opens a **self-closing window** through the agent (`ssh_window_open`, gated by the O10 approval signature; a standalone node's admin opens it locally; closes on timer and on reboot); rung 6–7 ceremonies begin by opening the window. If the agent is dead, the provider rescue boot is the floor — so hardware with no rescue path keeps sshd running, stated at install. **Honest scope:** this eliminates the standing remote-auth attack surface (the §3.7 "node root" row loses an entry path) and does nothing against privilege escalation from a compromised web tier, which attestation (§3.6) covers instead. New installs default off; existing nodes adopt at their Phase 3 cutover.
- **A6. Enrollment secret — RESOLVED (owner-directed, 2026-08-26): there is none.** Enrollment is a **node-initiated join** (Phase 1.5): the node's root agent generates its keypair and asks; a human approves after comparing fingerprints across the two admin panels. The Phase-1 plane-minted pairing token is retired — it required copying a secret and editing a file over a shell that A5 removes, and it put a credential in a browser, a clipboard, and the node's web tier on its way to where it was used. Also owner-set here: the user-facing name for the plane is **"management node"**, everywhere.
- **A7. Transport routing — RESOLVED (owner-directed, 2026-08-26): connecting IS the cutover; there is no per-node routing switch.** Approving a node's join is the operator's routing decision — from that moment every operation with a primitive runs on the node's agent, and each new primitive takes effect fleet-wide as it ships. A soft-launch flag ("connected but still routing over SSH") was built and removed: it added a second decision that means the same thing as the first, and the fallback it offered is not needed — if an agent path misbehaves mid-migration, the fix happens over SSH by hand, which still exists until Phase 3. Disconnecting the agent is the (visible, deliberate) way to return a node's work to API/SSH — and the disconnect is symmetric: the plane forgets the key from the node detail page, and the node's own Management Node panel can leave (the agent sends one best-effort signed goodbye to `/api/v1/agent/leave`, deletes its identity, and returns to local-only work). Sovereignty demands the second half: the machine being managed can end the arrangement without the plane's cooperation, so a leave completes even when the goodbye cannot be delivered.

---

## 8. Components

- **A. Agent: remote job source + enrollment** · *medium* — HTTPS polling mode, claim/backoff, result posting, node-generated identity storage (root-owned, node-side), join request + status polling (Phase 1.5), node-side leave (A7).
- **B. Agent: primitive framework** · *medium* — vocabulary registry, param validation, class policy enforcement, manifest verification for script-invoking primitives. **This is the security boundary; it gets the care §14.D got in the sentinel spec.**
- **C. Primitive implementations** · *large in aggregate, small each* — the §4 table. Mostly thin wrappers over scripts that already exist and are already what the SSH steps invoke.
- **D. Plane: channel endpoints + routing** · *small–medium* — join request intake + pending/approve/reject states with the fingerprint-comparison UI (Phase 1.5), claim/result/leave API, `JobCommandBuilder` routing (`build_<op>_primitive` joins `_api`/`_ssh` in `transports_for()`; a connected agent is routed to unconditionally — A7). Plus the node-side "Connect to a management node" core panel.
- **E. Migration tooling + gate** · *small* — Phase-0 inventory report, cutover checklist on the node detail page, the deploy-tier gate test.
- **F. Exfiltration rules (§3.5)** · *small–medium* — backup key un-supply + node-pinned sealing with the loud no-key refusal and transition handling (shippable ahead of the transport, A4); node-side redaction in the agent result path (secrets + personal-data pass); the collector source allowlist; the management-API endpoint pin test; collector volume alerting; storage object-lock retention + the standing delete-refusal check.
- **G. Code integrity attestation (§3.6)** · *medium* — **publish-side per-file tree manifest signing is new work, not reuse** (the pipeline signs only the agent bundle today — §2); then the scheduled two-sided verify, the unexpected-executable sweep, attestation-on-heartbeat with silence-as-failure, and the standalone-node notify path. The same manifest is what §3.2's script-invoking primitives and the `apply_update` verification consume, so G sits earlier in the build order than "attestation" suggests. The static-only unlock-surface rule is core/vault work, referenced here, not agent work.

---

## 9. Relationship to the sentinel spec

This spec **is** the sentinel's §14.N and vocabulary channel, built fleet-first. What the sentinel spec adds on top, unchanged: multi-tenant ownership and enrollment (§12), the sealed-SSH owner-present channel for destructive rungs (§6/§13.O9) — which A2 makes the *only* destructive dispatch path fleet-wide, not just for customers — consent tiers, the incident record, and the plane productization. Sentinel build begins after Phase 3 here (the fleet migrates fully first, per the owner), with its component J moved ahead of destructive-rung proving per A2.
