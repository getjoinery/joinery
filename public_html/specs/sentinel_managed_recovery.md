# Sentinel — Managed Recovery for Joinery Nodes

**Status:** **READY FOR BUILD — 2026-08-25, unbuilt.** Owner direction 2026-08-25 inverted the 2026-08-22 box-first draft: **the default sale is a centralized subscription service run by getjoinery; the customer-owned sentinel box becomes the power-user posture** — the ScrollDaddy pattern. The spec was rewritten the same day, **all eight decisions the inversion opened (O1–O8) were resolved with the owner in walkthrough**, and a second owner correction the same day (**§13.O9**) re-architected credential custody: **the plane holds no credential that can act destructively while unattended** — routine repairs run through an enumerated node-side tool vocabulary over the API, and destructive rungs require the customer to log into the sentinel UI and unlock a sealed SSH key (§6). Decisions carried from the box-first draft are marked **CARRIED**; those the inversion replaced are **SUPERSEDED**.

**Prerequisite (owner-set, 2026-08-25): `specs/agent_on_node_architecture.md` — the fleet-wide Server Manager transport migration — completes before this spec's build begins.** *(2026-08-30: that migration's destructive gate is now built and shipped at agent 1.13.0 — see the note in §6. It does not resolve O10 for sentinel; it changes what O10 can assume it inherits.)* The same skeleton-key vulnerability O9 closes for customers exists in Server Manager today (one shared SSH key, arbitrary command steps); the platform migrates first, and this spec's vocabulary channel (§6, §14.N) is that spec's architecture, consumed rather than defined here.

**Origin:** Owner feedback that prospective self-hosters fear their Joinery going down with no skills to fix it. The box-first draft answered the sovereignty objections but aimed the product at the wrong buyer: the person frightened of their site dying is exactly the person who will not provision, pay for, and maintain a second VPS. A second box is a power-user feature. The service is the product.

**The remediation machinery this spec assembles already exists** (`JobCommandBuilder` builders, `reconcile_site.sh`, backup chains, `NodeMonitorHealth`, the Sealed Vault). What is new: the **incident record**, the **ladder driver**, the **database restore check**, the **node tool vocabulary + agent node posture**, the **sealed-key custody and unlock flow**, **multi-tenant ownership + enrollment**, and the **sentinel plugin** packaging. Build order is §15.

---

## 1. The product in one paragraph

When someone's Joinery breaks, a guardian they have enrolled with notices within minutes and fixes everything that can be fixed without touching data: restarts, disk, certificates, a forced reboot. If the fix needs a restore, the guardian stages everything — evidence gathered, a fresh snapshot where possible, the verified backup identified — and fetches the owner: **one login, one unlock, one click runs it.** It works through a fixed list of repairs, not by improvising, and every step it takes is written down where the owner can read it. The promise is not "your site will never go down." The promise is **"your site comes back, working, you lose no more than X of data — and nothing destructive ever happens without you."**

That promise is possible because the worst case is bounded. Diagnosis has no upper time limit; **wipe and restore** does. The AI gets a fixed budget to be clever, then a deterministic path takes over. The guarantee is not "our AI is smart" — it is "our worst case terminates."

---

## 2. One plugin, two postures

### 2.1 The shape is the ScrollDaddy shape

ScrollDaddy is the template already proven in this codebase: a **plugin** (`dns_filtering`) owns the data model, customer UI, API surface, and tier gating; it runs on a **dedicated deployment** (scrolldaddy.app — its own accounts, its own store, its own subscription tiers via `sbt_subscription_tiers.sbt_features`); and a **separate engine** does the actual work against the data the plugin manages. Sentinel mirrors this exactly:

| Role | ScrollDaddy | Sentinel |
|---|---|---|
| Plugin (data model, customer UI, tiers, enrollment) | `dns_filtering` | **`sentinel` (new)** |
| Execution engine | Go resolver | **`server_manager`** (agent, job queue, `JobCommandBuilder`) |
| Optional intelligence | — | **`joinery_ai`** (rung selection, §5) |
| Where it runs | Dedicated deployment | The **sentinel plane** (O3), or the customer's own box |

The `sentinel` plugin **depends on** `server_manager`; it does not duplicate it. Server Manager stays the operator's tool (permission-10, whole-fleet); the sentinel plugin is the productized layer on top: customer-scoped ownership, enrollment, consent tiers, the incident record, the coverage dashboard, and billing hooks.

### 2.2 The two postures

- **Service posture — the default sale.** The sentinel plane is operated by getjoinery. Customers subscribe, enroll their node (§12), and the plane watches and repairs it. One paid plan, priced per enrolled node, over a free monitoring tier (O1/O2). **The plane holds no credential that can act destructively while unattended** (§6, §13.O9).
- **Box posture — the power-user option.** The same bundle — `["server_manager", "joinery_ai", "sentinel"]` — installed on a customer's own minimal second VPS. Everything in this spec applies unchanged; custody of every credential sits with the customer; nothing phones home. Sold as a **one-time provisioned install** (the §13.5 shape, carried for this posture). A box with no subscription never degrades — it keeps its runbooks, keeps watching, keeps repairing (§13.1's never-degrades rule, kept).

One codebase, two postures, not two products. The posture is a deployment fact, not a fork: a plane has many owners' nodes; a box has one owner's.

### 2.3 What the inversion changes, and what it does not

**Survives untouched:** the remediation ladder (§4), the incident record (§10, §14.B), the database restore check (§9), the guard rails (§7, §14.D), runbooks-as-data over a fixed vocabulary (§13.12), the consent tiers (§13.2), and — critically — the key-custody decision (§13.7): **no sentinel, hosted or not, ever holds a key that opens the customer's backups.** That property was designed for the box, but it is what makes the hosted version sellable: *"we can rebuild your server; we cannot read your backups"* is the trust story, and it is verifiable.

**Changes:**

| | Box-first draft | Service-first |
|---|---|---|
| Per-node support credentials (§6, §14.J) | Good hygiene | **Mandatory before the first external customer** — a shared key across customer machines is disqualifying |
| Privacy redaction (§11, §14.E) | Arrives with the AI phase | **Mandatory v1** — diagnostics cross a trust boundary to the plane |
| Multi-tenancy (§12) | Not needed | **New, and the largest new work item** |
| Enrollment (§12) | Provisioning-time setup | **A customer-facing flow with a consent story** |
| Second vantage (§7.2) | Mutual node probing, getjoinery endpoint later-phase | **The plane + an independent getjoinery probe location, from day one** (O6) |
| Billing | One-time install product | **Subscription tiers** (O1/O2), same `sbt_features` machinery as ScrollDaddy |

### 2.4 The old objections, answered instead of dissolved

The box-first draft's §2.1 table listed the three objections to a hosted service and dissolved them by not hosting. Hosting brings them back, and they must be answered head-on:

| Objection | The service's answer |
|---|---|
| "Why would I give you root on my server?" | You don't. Routine repairs never use SSH at all — your node's agent executes an **enumerated tool vocabulary compiled into it**, nothing else (§5, §6). The SSH key for destructive repairs exists, but it is **sealed to your credential**: we cannot use it until you log in and unlock. You can revoke everything with one button on your own box, without asking anyone (§12). |
| "One breach of your support plane exposes every customer." | **There is no skeleton key to steal.** The plane holds no credential that can act destructively unattended (§13.O9): destructive keys are sealed to each customer's vault credential — a fully compromised plane cannot decrypt a single one — and the unattended channel can only queue jobs from the enumerated vocabulary, which each node's agent enforces itself (§5). The plane is also a dedicated minimal deployment (O3). |
| "Your AI reads my error logs, which contain my members' data." | Diagnosis is menu-driven collectors with known output shapes, a personal-data redaction pass runs before anything reaches a model (§11), consent is collected at enrollment — and your backups stay unreadable to us regardless (§13.7). |

**Honesty note for the sales copy:** these measures *narrow* the trust ask; they do not eliminate it. The accepted limits are written down as a promise boundary in `specs/agent_on_node_architecture.md` §3.7 — customer-facing copy derives from that table rather than rounding up to "unhackable." The box posture remains the full-sovereignty answer, and saying so plainly is what makes the service's own claims credible — the same "verify, don't trust" register as the rest of the site.

### 2.5 The guarantee splits along the machine/human seam

- **The machine guarantees resolution.** Bounded ladder, measured restore checks, published record. Verifiable by the owner from their own dashboard; requires no trust.
- **No human response window is promised in v1** (O4). Escalation pages the owner through existing alert paths. A named-human response window is a later, separately-priced tier — it is a relationship, and it puts a person on the hook, which is precisely what §13.1 refused to do casually.

You cannot write an uptime SLA over infrastructure you do not control, and should not try. Publish measured facts and let the record be the guarantee.

---

## 3. The guarantee, stated precisely

> **Your site back and working, losing no more than X of data, within Y of confirmed detection.**

A **data-loss guarantee, not an uptime guarantee.** Restore returns a site to a point in time, not to now.

**X is a function of the failure class, and all three branches are stated to the customer:**

| Situation | Data loss |
|---|---|
| Environment broken, state good — resolved at rungs 1–2 or 6–7 | **Near zero** — a fresh backup is taken *before* any destructive rung, and the current data is restored onto clean ground |
| The fault lives *in* the state — resolved at rungs 3–5 | Back to the **newest good run**. This branch cannot be "near zero" even on a fully reachable node: a fresh backup of a poisoned state restores the poison, so these rungs deliberately go back |
| Node hard-down / unreachable | Back to the **last scheduled run** |

The near-zero branch carries a precondition: the fresh pre-destructive backup needs PostgreSQL up, credentials valid, and free disk (§4.4). A node broken in exactly those ways falls to the next branch.

Y is not one number: rungs 1–2 (and the §4.5 self-recovery reboots) run unattended on the machine's clock; **every destructive rung waits on the owner's signed approval** (§6 — node-verified, by construction; rungs 6–7 additionally need the vault unlock for the sealed SSH key). The driver pre-stages everything while paging — evidence, the fresh snapshot where possible, the verified chain point — so the owner's share of Y is minutes of presence, not hours of work. Customer-facing copy states the unattended window and the owner-present window separately rather than averaging them into a figure that is true of neither.

X is therefore a function of backup frequency — which is a plan-standard setting, not a pricing dial (O2: one paid plan, priced per node, full ladder for everyone).

**One honest footnote for customer-facing copy:** within a single run the file archive and the database dump are taken minutes apart, so a backup has no single point-in-time (`docs/backups.md`, "How chains work"). The guarantee language must not imply transactional consistency across the two.

---

## 4. The remediation ladder

Every rung already exists as a job builder. The ladder is the product; the driver is the new code. Identical in both postures.

| Rung | Action | Existing mechanism | Disruption |
|---|---|---|---|
| 1 | Restart services (php-fpm, postgres, container, agent) | new builders, trivial | none |
| 2 | Reclaim disk, renew certificate, finish a half-applied upgrade | new builders | none |
| 3 | Restore database only | `build_restore_database` | data to last run |
| 4 | Restore newest chain point (files + database) | `build_restore_chain` | data to last run |
| 5 | Walk the chain back — `--seq N` | `build_restore_chain` with `--seq` | data to an older run |
| 6 | Reinstall Joinery in place, then restore | `build_install_node` + restore | full site rebuild |
| 7 | Provider rebuild → full install → restore | provider API + rung 6 | whole machine |

**Rung 2 earns its place from real history.** The upgrade two-pass behaviour is a known, recurring, diagnosable outage on this platform. It belongs in the library as a first-class named repair, not something an AI rediscovers each time.

**Rung 5 matters more than it looks.** If the cause lives *in* the restored state — a bad migration, a poisoned setting, a plugin that crashes on boot — then restoring the newest point restores the cause. Walking the chain back is the escape, and because each step trades data for certainty, **it converges**. That is the difference between a procedure and a loop.

**Rung 7 is the only rung requiring a destructive provider credential** — by far the largest consent ask. In **service posture the plane holds no standing provider tokens in v1** (O7): machine-loss recovery is a human-driven ceremony. In **box posture** the §13.4 decision carries: a separate, plainly-described grant, rebuild-in-place only.

### 4.1 Rollback is already free

Restores leave `auto_pre_*` rollback snapshots behind. Second-order benefit worth designing around: **the driver can safely attempt riskier repairs than it otherwise could**, because every attempt is reversible. The ladder is not just a fallback — it is what makes the earlier rungs affordable.

### 4.2 Restore needs no key ceremony through rung 5 — one click after unlock

Confirmed from `docs/backups.md`, and load-bearing for this whole spec:

- `config/backup_site_key` exists specifically so that "pre-restore rollback snapshots and routine restores need no operator." **No recovery key, no customer, no ceremony** on the routine path.
- `reconcile_site.sh` makes a restored site agree with the machine it lands on, including **across shapes** (container ↔ bare metal), and always regenerates serving config rather than copying it.
- The target's own `config/Globalvars_site.php` and `config/backup_site_key` **survive every restore** — the thing that otherwise turns a clean-looking rebuild into `SQLSTATE[08006]`.
- TLS resolves itself: the reconcile arms `joinery-ssl-retry@{domain}.timer`, the site serves HTTP until DNS resolves, then issues once. **Restore now, cut DNS later, certificate arrives on its own.**
- Restores replay **deletions**, so a restore is a true point-in-time and not an additive merge.

Mechanically self-sufficient, then — rungs 3–5 need no recovery key and no shell work. In service posture the driver still may not *start* them without the owner's unlock (§6, §13.O9); the value of this machinery is that once the owner clicks, the restore runs to completion with no further ceremony. Box posture may keep standing unattended restores per the box-first draft — one customer's own box holding that customer's own keys is not the target this rule exists for.

### 4.3 The key-custody discontinuity at rungs 6–7

**This is the real constraint on the guarantee, and it is a hard one.**

A chain's data key is recovered **on the node, from the node's own `config/backup_site_key`** — `build_restore_chain` says so in as many words, and adds that "the management node's recovery private key never travels." That is what makes rungs 3–5 unattended.

Rungs 6 and 7 destroy that key. A wiped or rebuilt machine mints a *fresh* `backup_site_key`, and a fresh key does not open a chain sealed to the old one. The restore path already handles this explicitly and hands off to a human:

> `This node has no backup_site_key, so it cannot open its own chain.`
> `Recover the key with backup_envelope.php open --sidecar manifest.json --private <recovery key> and restore from a shell.`

The recovery private key lives in the **customer's** custody and never touches a server — in both postures. So **the ladder is unattended through rung 5 and requires the customer at rungs 6–7.** Resolved at §13.7 (CARRIED): human ceremony now, `specs/managed_backup_recovery.md` as a committed dependency of the full guarantee, and **escrow closed permanently** — the plane holding key material that opens customer archives is exactly the custody blur this design exists to avoid, and it must not be reopened under schedule pressure, service posture or not.

### 4.4 The pre-destructive snapshot is a hard gate

Every destructive rung's first step is a snapshot — `pg_dump` piped to the node's `/backups`, plus a tar of the tree on the project path — and **if any pre-backup step fails, the destructive steps never run** (the job aborts). The snapshot needs PostgreSQL up, credentials readable from `config/Globalvars_site.php`, and free disk; there is no free-space check anywhere in the restore scripts. So a node whose postgres is dead or whose disk is full stalls at rung 3/4's own safety gate — exactly the failure modes those rungs exist for. Rung 2 (reclaim disk) clears one branch; postgres-dead has no path through except the §13.10 consented skip (CARRIED: decided at enrollment, default off, skip permitted only where the snapshot could not have captured anything anyway).

These snapshots are also **plaintext by design** (rollback artifacts on local disk, swept by local retention) — see §6 for the confidentiality scope.

### 4.5 Rebooting without a provider credential

O7 removed provider tokens from the service posture, so machine-level recovery is built from the node side, in three layers:

- **While SSH answers, reboot as hard as needed.** Escalating primitives in the rung library, each individually admissible in the sudo allowlist: `systemctl reboot` (clean) → `systemctl reboot -ff` (immediate reboot syscall, skips service shutdown — works when systemd's job queue is wedged) → sysrq `b` (`echo b > /proc/sysrq-trigger` — the kernel's reset lever; if the kernel is alive at all, this works).
- **When SSH is dead, the node saves itself.** Enrollment arms self-recovery (§12.2): `kernel.panic = 10` and `kernel.panic_on_oops = 1` (a panicked kernel reboots after 10 seconds instead of hanging forever — hang-forever is the default), plus a software watchdog (`softdog` + the watchdog daemon, or systemd `RuntimeWatchdogSec`) that reboots the machine when userspace stops responding. The watchdog catches the common OOM-lockup case where sshd is killed but the kernel is fine.
- **The remainder is a guided ceremony.** Hypervisor-level freezes and wedges deep enough to kill kernel timers page the owner with one instruction — *reboot the machine from your own provider console* — and **the plane resumes the ladder automatically the moment SSH answers.** The incident record carries the instruction and the resume.

---

## 5. The AI drives, but it does not hold the wheel

**The AI never authors a command string.** It selects a rung and supplies parameters; the server builds the steps. This is already how `JobCommandBuilder` works and the driver must not add an escape hatch.

Each rung is a runbook-as-data — **precondition, action, verification, rollback** — matching the executor-supervision pattern already used elsewhere in the platform.

**The clock, not the cleverness, is the guarantee.** The driver holds a budget. While the budget lasts, the AI may diagnose, gather evidence, and choose among low rungs. When it expires, the ladder advances on its own. An AI that wants an action not in the library does not get it — that request is an **escalation trigger** (§8), and a signal that the library needs a new entry.

**The same discipline applies to reads.** Diagnosis is menu-driven too: the AI chooses among named evidence **collectors** (§14.E), never a free-form shell read. This is load-bearing for §11, not just §5 — a redaction pass is only writable over output whose shape is known, and a free-form read is an exfiltration channel into the model context for exactly the data the customer was promised stays home. Collectors are reads: cheap to add, no side effects, so the diagnostic menu can grow far faster than the repair menu. A collector the AI wanted and did not have is the same library-gap signal as a missing rung.

**Allowlist enforcement belongs on the node, not only on the guardian.** Two node-side enforcement points, one per channel (§6): the unattended vocabulary is **compiled into the node agent** — a job naming anything outside the enumerated tool set is refused on the node itself, whatever the plane says — and the owner-present SSH path lands on a restricted support account whose sudo allowlist admits exactly the destructive rung vocabulary. In service posture this is not hardening, it is the product's central trust claim (§2.4): **a compromised plane cannot talk a node into arbitrary work, and cannot even begin destructive work, because it cannot decrypt the key that carries it.**

**Where the model runs, per posture:** in service posture the model is configured on the plane, behind the enrollment consent of §11 — and the ladder is fully functional with no model at all, so a customer who declines gets the deterministic ladder, undegraded. In box posture, §13.3 carries: bring-your-own via `joinery_ai`'s provider configuration, capability floor enforced by the existing model-capability-resolution system, default none.

---

## 6. Credentials — two channels, and no skeleton key

**The design rule (owner-set, §13.O9): the plane holds no credential that can act destructively while unattended.** Everything the driver does on its own initiative travels the vocabulary channel; everything that can lose data travels a key only the customer can decrypt. A plane that cannot hurt anyone unattended is not worth attacking the way a vault of usable SSH keys is — that is the whole point.

**The vocabulary channel (unattended).** Guarded nodes run the platform's signed agent in **node posture**: a root systemd service, independent of the web stack (so it survives php-fpm, Apache, and postgres being dead), that **polls the plane outbound over HTTPS** and executes only an **enumerated tool vocabulary compiled into it** — service restarts, the §4.5 forced reboots, disk reclaim, certificate renewal, upgrade completion, the §14.E evidence collectors, pre-destructive snapshot staging, and the database restore check (§9). The node holds a pairing credential to reach the plane; **the plane holds nothing that reaches into the node.** A fully compromised plane can queue vocabulary jobs — bounded, non-destructive, logged in the incident record — and nothing else. The node also runs its own backup schedule locally (it is a full Joinery site); the plane monitors that runs land, it does not conduct them.

> **THE FLEET-WIDE GATE SHIPPED FIRST, AND IT IS NOT A PASSKEY (2026-08-30).**
> `specs/implemented/restore_dispatch_approval_mechanism.md` built the
> destructive gate for Server Manager's own fleet, and it answers with the
> node's **backup recovery key**, not a registered approval passkey: the node
> seals a job-bound one-time challenge to the recovery public key it already
> holds and has already seen proven, stages it on its own admin page, and the
> human opens it in their browser with the private half. No signature, no
> WebAuthn on the node, no second credential to register at enrollment — and
> critically, **no account on the plane**, which is what a passkey would have
> required and what would have made the plane the issuer of its own approvals.
>
> **This section is not superseded, because sentinel's case is genuinely
> different and this spec has to settle it.** Two things do not carry: a node in
> trouble may not be serving its own admin page, which is exactly when rungs 3–5
> fire; and a *customer's* recovery key is a backup credential, so binding
> repair approval to it means a customer who has lost that key can neither
> restore nor be repaired. Either sentinel keeps a passkey for its own rungs and
> accepts holding two mechanisms, or it finds a third answer. **What it must not
> do is assume it inherits this one.** The statement below stands as written
> until sentinel's own round decides.

**The approval gate (owner-present — rungs 3–5, O10).** Destructive repairs travel the same vocabulary channel, but the agent executes a `destructive` primitive only when the job carries an **approval the node verifies itself**: at enrollment the customer registers an **approval passkey** whose public key is stored root-owned on the node; to approve a staged repair, the customer signs the node-issued challenge — which binds that specific job — and the agent checks the signature before opening the door. **The plane is a relay, not the gate**, so even a plane compromised deeply enough to capture a customer's unlock credential holds nothing that opens any node (§13.O10). When the node is healthy enough to serve its own admin, approval happens there — true origin, no plane page involved at all. Every destructive-door opening fires an out-of-band notification. The consequence stands from O9: **destructive repairs wait for the owner** (§3).

**The sealed-key channel (owner-present — rungs 6–7 and break-glass).** A machine being reinstalled or rebuilt has no agent to verify anything, so those rungs keep SSH — and since sshd is off by default (migration spec A5), the ceremony begins by opening the node's self-closing SSH window through the agent while it still runs: each node's keypair is minted at enrollment and the private key immediately **sealed to the customer's vault identity** (`docs/sealed_vault.md` — sealing *to* an identity is public-key encryption and needs no ceremony, so the plaintext key never rests anywhere). Decryption happens only inside the customer's unlock window — they step up with their **vault credential** (passphrase-derived key or passkey PRF, deliberately *not* the account password) — and the driver receives the key in memory via the existing `__SM_CREDS__` placeholder pattern, never on disk. The SSH path lands on a restricted support account whose sudo allowlist admits exactly the rung 6–7 vocabulary — defense in depth. Sealed content being unreadable by unattended code is already a hard platform invariant; putting the working credential behind it is the point.

| Class | What it is | Custody | Used by |
|---|---|---|---|
| **Node pairing credential** | API credential the node's agent uses to poll for vocabulary jobs and post results | On the node; the plane stores only a verifier | The agent, unattended — rungs 1–2, collectors, staging, restore check |
| **Approval passkey** | The customer's authenticator; its public key sits root-owned on the node, which verifies every destructive approval itself (O10) | Private key on the customer's device, never anywhere else; node holds only the public key | The customer, owner-present — approving rungs 3–5 per staged job |
| **Sealed per-node SSH key** | Key to a restricted support account whose sudo allowlist admits the rung 6–7 vocabulary | Sealed to the customer's vault identity on the plane; plaintext exists only inside the customer's unlock window, in memory | The driver, owner-present — rungs 6–7 (the §4.3 recovery-key ceremony still applies) |
| **Break-glass access** | The provider's rescue boot from the customer's own account — nothing minted, nothing stored (§13.11, CARRIED) | Customer's provider account; never the guardian | A human, following the ceremony doc |

(Provider API tokens: none in service v1 — §13.O7. Box posture: the box-first draft's credential model stands, §4.2.)

**All of this is new work, and §14.J and §14.N cost it.** Today the fleet authenticates with **one shared provisioning keypair**, stored as a plaintext path on the management node's disk (`mgn_ssh_key_path`); there are no per-node keys, no restricted account, no sudo allowlist, no node-posture agent, and no sealed custody. SecretBox in Server Manager covers backup-target credentials and OAuth tokens — not SSH, and SecretBox is the wrong tool here anyway: a running plane can always open its own SecretBox, which is precisely the skeleton-key property this design removes. **The two channels gate the first external customer, not the packaging phase.**

**Why not simply store the root password.** Not revocable without the customer's help, cannot be scoped, very often reused elsewhere, and the fleet already authenticates with keys. Note also that a storable break-glass root does not exist on the current fleet — the installer disables root SSH, and provider-provisioned nodes get a random root password that is deliberately never stored.

**Revocation is a customer-side control.** The support account, its key, and the agent pairing all live on the customer's machine, so revocation is local and unilateral: one button in the node's own admin deletes them, effective immediately, plane reachable or not (§12). This is the answer to "what if I stop trusting this," and it needs to be visible, not buried.

**Backup confidentiality is not a prerequisite — it is already closed.** Encryption is the default on every path, and the automated paths cannot produce a plaintext archive at all:

- `backup_project.sh` and `backup_files.sh` default to `ENCRYPT=true`. A run that cannot find a key **fails** rather than downgrading.
- The chain path (`build_backup_run`) has no plaintext branch whatsoever — it always mints an envelope against the recovery public key.
- The two legacy ad-hoc builders can pass `--plaintext`, but only when an operator has explicitly unchecked encryption **and** the node has no cloud target.

An unencrypted archive that leaves the node therefore requires a deliberate human act at a shell. Two local exceptions, named rather than papered over: the `auto_pre_*` pre-restore snapshots of §4.4 are plaintext files on the node's own disk by design (rollback artifacts, swept by local retention, never uploaded), and the install-from-backup clone path passes `--plaintext` for its transfer. The confidentiality claim is scoped to what leaves the machine — which, in service posture, is also the claim that the **plane cannot read the backups it schedules** (§13.7, O5). That claim is load-bearing only because **the plane never chooses the encryption key**: backups seal exclusively to the node-pinned proven recovery key, and the old manager-profile per-run key supply is removed by the prerequisite spec (`specs/agent_on_node_architecture.md` §3.5.1/A4) — otherwise a compromised plane could silently re-seal a node's backups to a key it controls.

---

## 7. Detection

### 7.1 The incident opens itself

A down site cannot serve a ticket form, and waiting for the owner to notice burns the clock before it starts. Monitoring opens the incident. The ticket is the fallback path for problems monitoring cannot see (something works but is wrong), never the primary trigger.

### 7.2 The detector lies, and the platform already knows it

`NodeMonitorHealth` exists because monitoring breaks in ways that look like the fleet breaking. It already models `misconfigured`, `stale`, and `pending` as distinct from down, and `is_name_resolution_failure()` exists specifically because **a monitoring host whose resolver breaks fails every probe at once** — the false-down flood, on the record.

Rules that follow, non-negotiable and posture-independent:

- **No rung above 2 fires on a single vantage.** Confirmation must come from a second, independent observer.
- **"The detector is wrong" is a first-class diagnosis** the driver can reach and close on, with no repair attempted.
- **Correlated failure suppresses the ladder entirely.** If every node a guardian watches goes down at once, the guardian is the suspect, not the fleet. On the plane this is existential: a plane-side network or resolver fault must read as "I am broken," never as "every customer is down."

**Vantage supply, per posture (O6):**

- **Service:** the plane plus a **second getjoinery-operated probe location on an independent network** — a tiny HTTP probe endpoint, not a second management node. Two independent opinions from day one, for every subscriber including single-node customers — which was the box model's awkward gap, closed for free. A customer's other enrolled nodes count as additional vantages via the node-side probe task.
- **Box:** §13.6 carries — mutual node vantage is the default and the floor, no getjoinery dependency required. Once the service exists, its probe endpoint is available to box sentinels as a third opinion; a one-guarded-node box customer without it supplies any URL-probe they control or accepts a rung-2 ceiling, and the dashboard says which in plain words.

### 7.3 Who watches the watcher

1. **A guardian never guards itself.** The plane is monitored and recovered by getjoinery ops, not by its own ladder. A box never guards itself; the minimum box deployment is two machines, said in the sales copy.
2. **The guardian publishes a heartbeat the guarded nodes check** — inverted from the usual direction, and the only arrangement that survives the guardian dying quietly. This is the sentinel plugin's guarded-posture half (§12): a relationship record on the node plus a small scheduled task that checks the guardian's heartbeat URL and surfaces "your sentinel has not been heard from" in the node's own admin. Identical in both postures.
3. **The heartbeat attests the agent, not the web stack.** The guardian's agent process is its single point of failure: if it dies, every job sits `pending` forever and nothing complains. The heartbeat derives from the agent's last job-claim/poll time, so a guardian whose pages render but whose executor is dead reads as dead.
4. **A guardian backs itself up** to the same targets it manages. The plane's recovery is getjoinery ops' runbook; a box's recovery is a manual, documented customer procedure that ships with the box.

---

## 8. Escalation

A timer alone escalates slow problems and misses dangerous ones. Escalate **immediately**, regardless of clock:

- The AI requests an action not in the library.
- The same rung has failed twice.
- Anything touching backups, keys, or deletion outside a rung's own defined actions.
- Any indication of compromise rather than failure.
- Rung 7 is reached (a machine rebuild should have a human's eyes on it).
- The node has been paved more than N times in a rolling window. **This also protects against Let's Encrypt duplicate-certificate limits**, which a pave loop will otherwise burn.

The timer covers the remaining case: still grinding, nothing alarming.

**Who gets fetched:** in v1, **the owner**, through the existing alert paths — in both postures (O4: no human response window is promised). The commonest page in service posture is not an escalation at all but the **approval request** (§13.O9): non-destructive repairs exhausted, a restore staged and verified, one login runs it. In service posture, plane-side operational failures (agent dead, job backlog, correlated-failure suppression tripped) additionally page getjoinery ops — that is fleet operations, not a customer promise.

**Runbook distribution:** the plane updates with ordinary platform releases, so the service's library is always current by construction — the "runbook feed" problem largely dissolves in service posture. Box sentinels receive entries with platform releases (§13.12 CARRIED: runbooks are data over a fixed primitive vocabulary; new primitives are code and arrive via the signed upgrade channel). A live feed to boxes remains later-phase (§14.I); when it comes it is outbound-pull on the `MarketplaceClient` signed-catalog pattern — Joinery Direct is explicitly not the transport (its receiving half lives in the mailbox plugin, needs inbound exposure, and has no feed primitive).

---

## 9. The restore check is the investment

**An untested restore is a hope.** Current fleet state makes this concrete: retention deletion has never been live-tested, five nodes have no backup target, and the recovery key panel has an explicit `unproven` state. This spec makes proof a scheduled obligation.

**The full clone rehearsal rig is deliberately not built (§13.9, CARRIED).** Restoring a complete copy of a customer site is restoring a *live* site — working Stripe and SMTP credentials in plaintext settings, a cron tick that fires within a minute — and caging it safely demands a quarantine mode, another machine, and a consent story about where member data lands. Full-fidelity restore-path proof (files + reconcile + boot) happens at the **core level, on our own fleet, periodically**.

**Per-node proof is the database restore check.** On a schedule, the node restores its own newest backup dump into a throwaway *database name* — never over the live one — asserts it loads and row counts are sane, and drops it. No clone exists, nothing external is touched, no data leaves the machine. It converts "backups are landing" (already monitored) into "backups actually **open and load**," which catches the failure that matters most: a chain quietly unrestorable for months. Two hard requirements, both new code (no restore script today checks free space): a **disk-space preflight** that skips-and-reports "not proven — insufficient disk" rather than run without headroom, and **guaranteed cleanup** on every exit path — trap-based drop of the scratch database and temp files, plus an orphan sweep at run start for leftovers of a killed prior run. The check must never be the thing that fills a working box's disk.

**The check result is the coverage claim — and, in service posture, the free tier's product (O1).** A node whose last check failed — or was skipped for disk — is **not proven**, and its dashboard says so in those words. "Your last backup opened and loaded 6 hours ago" is a measured fact, and a better trust artifact than any SLA paragraph.

---

## 10. What the customer sees

One **incident record** per event, readable without technical knowledge: what was detected and from which vantages, what was tried, what each attempt did, why the driver moved to the next rung, what finally worked, and what data window was lost if any.

Standing dashboard state, always visible: last backup opened-and-loaded per node (§9), current data-loss window, monitoring health, active consent tier per node, destructive channel last verified (§12.2 step 6), **code integrity attested N minutes ago** (`agent_on_node_architecture.md` §3.6 — a stale or failing attestation is a first-class alert, not a footnote), which credentials are held — and that the destructive key is sealed and cannot be used without you — with a revoke control for each.

**Where:** in service posture, the customer's account on the plane, under the sentinel plugin's namespaced customer views (`/profile/sentinel/…`, per plugin view auto-discovery) — plus the node-side status surface ("your sentinel last heard from N minutes ago", the local revoke button) in their own site's admin. In box posture, the same plugin views on their own box.

**The record is the sale.** "Every action is on the record and you can revoke our access from your own admin at any moment" is the answer to "why would I let software do this to my server," and this architecture can actually back it up.

---

## 11. Privacy

Diagnosis means reading error logs, and those logs contain member email addresses and worse. In service posture, diagnostics cross a trust boundary — this section is **v1 work, not AI-phase work**.

- `SmSecretRedactor` already strips secrets from job output. **A parallel pass for personal data is required, and it runs on the node, in the agent's result path** (`specs/agent_on_node_architecture.md` §3.5) — plane-side redaction before a model protects the model, but the boundary is the node: nothing unredacted ever reaches the plane at all. It is writable only because collectors have known output shapes (§5).
- Consent is collected at enrollment, in plain words, per node: what diagnostic classes the plane may collect, and whether a model may process them. Declining the model leaves the deterministic ladder fully functional (§5).
- In box posture the model's location is the customer's choice (§13.3, CARRIED); local inference matches the platform's stated direction.
- Sealed content is never readable by the driver, and no rung may attempt to make it so. A restore returns sealed data intact and still sealed; it does not need to read it.

---

## 12. Multi-tenancy and enrollment *(new with the service posture)*

### 12.1 Ownership

Every guarded node belongs to a customer account, and every sentinel-plugin query is owner-scoped: a customer sees their nodes, their incidents, their coverage — and nothing else. Server Manager's own admin remains the plane operator's whole-fleet view (permission 10); the sentinel plugin's customer views are the tenant surface. Ownership is a column on the sentinel plugin's node-relationship record referencing the plane account; the plugin, not Server Manager, owns tenancy.

### 12.2 Enrollment — the grant is made from the customer's own admin

The customer's guarded node is a full Joinery site (unlike the box-first draft, where guarded nodes were bare SSH targets — that fact does real work here). Enrollment is **node-initiated, outbound, consented on the customer's own machine**:

1. Customer subscribes on the plane, establishes their **vault identity** there (the credential that seals the rung 6–7 key — passphrase or passkey, `docs/sealed_vault.md`), registers their **approval passkey** (O10), and receives an **enrollment code**.
2. In their own node's admin, they enter the code. The node calls the plane **outbound** (the `MarketplaceClient` catalog-fetch pattern — the node exposes nothing inbound it did not already).
3. The plane validates the code, mints the node's **individual SSH keypair**, **seals the private key to the customer's vault identity immediately** (plaintext discarded — sealing-to needs no unlock, §6), mints the **agent pairing credential**, and returns the public key plus the support-account specification — account name and the exact sudo allowlist, which the enrollment screen **displays to the customer before applying**.
4. The node creates the restricted support account, installs the key, applies the allowlist, stores the **approval-passkey public key root-owned** (O10 — the node is now its own destructive gatekeeper), enables the **agent's node posture** with the pairing credential (§6 vocabulary channel), arms self-recovery (§4.5 — panic sysctls + software watchdog), records the relationship (plane identity, heartbeat URL, consent tier), and reports its reachability details.
5. The plane confirms the agent is polling (vocabulary channel live), reads backup status, runs the first restore check, and the coverage panel goes live.
6. **In the same sitting**, the customer performs a **first unlock on the plane**: the sealed key is decrypted inside their window and the plane verifies SSH with a no-op probe — proving the destructive channel *before* it is ever needed. The coverage panel shows "destructive channel last verified N days ago" and re-verifies at every subsequent unlock; it cannot verify unattended (the key is sealed), and the dashboard says so honestly rather than pretending.

**Consent tiers (§13.2, CARRIED) are chosen at enrollment**: fix-things on by default, restore-from-backup one opt-in checkbox with its one honest sentence (the §13.10 snapshot-skip sub-option under it, default off), rebuild-the-machine not a checkbox at all. Changeable later from the plane dashboard; the incident record names the tier each action ran under.

**Revocation is local and unilateral:** one button in the node's own admin deletes the support account and key — effective immediately, plane reachable or not. The plane also offers "release node," which triggers the same removal cooperatively and closes the subscription seat.

### 12.3 The node-side half of the plugin

The sentinel plugin's guarded-posture pieces, active on any enrolled node: the enrollment logic, the relationship record, the sentinel-heartbeat check task ("your sentinel has not been heard from" in the node's own admin, §7.3), the probe task (vantage duty toward the customer's other nodes, §7.2), and the local revoke control — plus the **node-posture agent** of §6, the one standing service the posture adds (signed, shipped through the existing agent release channel, vocabulary compiled in). Everything is inspectable, outbound-only, and removable.

---

## 13. Decisions

### Carried from 2026-08-22 (resolved; survive the inversion)

- **§13.2 Consent tiers — CARRIED.** Three plain-language tiers, not a per-rung matrix; tier 2 is one checkbox with one honest sentence; tier 3 is inherently human-present. Now collected at enrollment (§12.2).
- **§13.3 The model — CARRIED for box posture** (BYO, capability floor via model-capability resolution, default none). Service posture: plane-side model behind §11's enrollment consent; deterministic ladder undegraded without it.
- **§13.4 Rung 7 as a separate grant, rebuild-in-place only — CARRIED for box posture.** Service posture v1 holds no provider tokens at all (O7).
- **§13.7 Key custody — CARRIED, and load-bearing.** Human ceremony at rungs 6–7 now; `specs/managed_backup_recovery.md` a committed dependency of the full guarantee; **escrow and pre-seeding closed permanently — not reopened by the service posture, under any schedule pressure.** "The guardian holds no key that reads your backups" is the service's central sales claim (§2.4).
- **§13.8 Licensing — CARRIED for the box.** A sentinel box consumes no second licence (grant sentence + `manifest_licensing_test` pin land with packaging). The hosted plane is getjoinery's own deployment; no customer licence question arises.
- **§13.9 No clone rehearsal rig — CARRIED.** The database restore check is the per-node proof; full-fidelity restore-path proof runs at core level on our own fleet.
- **§13.10 Snapshot-skip consent — CARRIED.** Decided at enrollment, default off, skip permitted only where the snapshot could not have captured anything anyway; mid-incident consent never solicited.
- **§13.11 Break-glass — CARRIED.** The provider's rescue boot from the customer's own account; nothing minted or stored; the recovery key is the floor's single irreplaceable member. When even the provider account is gone: the box is disposable, the backups are the asset, restore to fresh ground.
- **§13.12 Runbooks are data — CARRIED.** Records over an enumerated primitive vocabulary; new primitives are code via the signed upgrade channel; diagnosis menu-driven the same way.

### Superseded by the inversion

- **§13.1 "v1 is the box, no subscription" — SUPERSEDED.** v1 is the subscription service; the box is the power-user posture, built after the service works (§15). The never-degrades rule survives for the box; its service translation is O8. The refusal to promise a human response window survives as O4's recommendation.
- **§13.5 One-time pricing — SUPERSEDED for the service** (subscription tiers, O2); **carried for the box** (one-time provisioned install).
- **§13.6 Vantage — PARTIALLY SUPERSEDED.** The getjoinery probe vantage moves from later-phase to v1 (O6). Mutual node vantage carries as the box floor and as extra vantages for multi-node service customers.

### Resolved 2026-08-25 — the inversion walkthrough (O1–O8)

- **O1. Free tier — RESOLVED: yes.** Free: uptime monitoring, down alerts, and the database restore check with its "backup opened and loaded" dashboard. Paid: all remediation. The protective baseline is ungated (the ScrollDaddy free-floor analog), and the free dashboard is the funnel — it shows exactly what the paid tier would have fixed.
- **O2. Tier structure — RESOLVED: one paid plan, priced per enrolled node.** Full ladder for every subscriber — no capability slices, so no "your tier didn't cover that repair" story is possible. Backup frequency is a plan-standard setting, not a pricing dial. Feature keys reduce to the seat count (purchased seats vs enrolled nodes) plus the free/paid remediation gate. Prices deliberately open until launch.
- **O3. The plane — RESOLVED: dedicated minimal deployment** with its own accounts and store — the scrolldaddy.app precedent. Custody isolated from getjoinery.com's mail/commerce/public surface; no SSO in v1.
- **O4. Human response window — RESOLVED: none promised in v1.** The machine guarantees resolution (§2.5); escalation pages the site owner. A named-human window is a later, separately-priced managed tier.
- **O5. Storage — RESOLVED: plane-provisioned by default, bring-your-own optional.** Enrollment provisions a per-customer bucket; backups land envelope-encrypted to the customer's recovery key, so the plane stores bytes it cannot read — §13.7 made demonstrable. **Buckets carry object-lock retention, so bytes the plane cannot read are also bytes it cannot delete** — a compromised plane must not be able to destroy the backups the whole guarantee stands on (`specs/agent_on_node_architecture.md` §3.5.7; the delete-refusal proof is a standing scheduled check on the coverage panel). Storage cost lives inside the per-node price; needs bucket provisioning + usage accounting (§14.M).
- **O6. Second vantage — RESOLVED: a getjoinery-operated probe endpoint on infrastructure independent of the plane.** It answers "can you reach this URL and what did it say," nothing more. Plane and probe must agree before rungs 3+; disagreement marks the plane suspect. A customer's other enrolled nodes add further vantages.
- **O7. Provider tokens — RESOLVED: the plane holds none in v1.** The deciding fact: on most providers (Linode included) a token is account-wide — it reaches every instance in the customer's account, not just the guarded node — so "reboot-scoped" is a fiction there, and the target buyer's *other* Linodes are exposed by a grant sold as protecting one. The hung-machine gap is closed node-side instead (§4.5): escalating forced-reboot primitives over SSH while SSH answers; self-recovery arming at enrollment (panic sysctls + software watchdog) for when it does not; and the guided owner ceremony — reboot from your own provider console, the plane resumes automatically the moment SSH answers — for the truly dead remainder. Box posture keeps §13.4 (the customer's own sentinel holding the customer's own token is a different custody question). Revisitable in a later phase; the reverse — asking customers to revoke tokens already granted — is not a good look, which is another reason to start without.
- **O8. Lapse — RESOLVED: drop to the free tier, plus a storage grace window.** Remediation stops; monitoring, down alerts, and backup proof continue free; nothing on the customer's machine breaks, nags, or phones home differently. Plane-provisioned backups get a stated grace window (60–90 days) to export or re-point to the customer's own target before deletion, with the deletion date shown on the dashboard. Stated in plain words at signup: what you paid for stops; what is yours remains yours.
- **O9. Credential custody — RESOLVED (owner-directed, 2026-08-25, after O1–O8): the plane holds no credential that can act destructively while unattended.** The concern that forced it: a plane holding every customer's *usable* SSH keys is the biggest target on the internet — SecretBox-at-rest is no defense, since a running plane can always open its own SecretBox — and some customers' own account security (standard unencrypted email) makes plane-account compromise likely enough to plan for. The resolution is §6's two channels: an **enumerated node-side tool vocabulary** run through the API (a compiled-in agent vocabulary, outbound-polling, node-enforced) for everything unattended, and **destructive rungs only via an SSH key sealed to the customer's vault credential**, decrypted by logging into the sentinel UI and stepping up — not by the account password alone. Accepted consequences: (a) **restores are owner-present in service posture** — the driver pre-stages while paging so the owner's share is one login, one unlock, one click; a customer who never shows stays down at whatever rungs 1–2 could not fix; (b) §13.2's tier-2 standing opt-in is **moot in service posture** — destructive work is human-present by construction (tiers 1 and 3 unchanged; box posture keeps all three); (c) §13.10's snapshot-skip question moves from enrollment to the **approval screen** in service posture — the owner is present, so it is asked live with the facts in front of them (the enrollment-time answer still governs box posture); (d) a signed node-posture agent joins each guarded node — the one standing software component the service adds, via the existing agent release channel.
- **O10. The destructive gate moves to the node — RESOLVED (2026-08-25, closing O9's residual).** The identified gap: a *persistently* compromised plane can serve a tampered unlock page and capture the customer's vault credential at their next login — the universal limit of web-delivered crypto, which code-integrity attestation (`agent_on_node_architecture.md` §3.6) detects on a minutes clock but cannot prevent inside its window. Resolution: **node-verified approvals** (§6) — the customer's approval-passkey public key lives root-owned on the node, the agent verifies the signature over each staged job's challenge itself, and the plane becomes a relay. A captured vault credential alone opens nothing; rungs 3–5 move onto the vocabulary channel behind this gate; the sealed-SSH channel shrinks to rungs 6–7 and break-glass, where no agent exists to verify. The same mechanism is the fleet-wide destructive path (the migration spec's A2 human-present channel — the operator approves with their own passkey). **Residual, stated honestly:** during a live approval on a compromised plane, the attacker could swap *which* staged destructive action gets signed — bounded to the destructive vocabulary, that customer's own node, only at the moment of active approval, and made loud by the out-of-band notification every destructive-door opening fires; approving on the node's own admin when it is reachable removes even that.

---

## 14. Components

Sized by scope, not schedule. "Reuses" names what already exists; only the **New** column is work.

### A. Rung library — the runbook  ·  *largest, and never finishes*

The enumerated repairs, each carrying precondition, action, verification, rollback.

- **Reuses:** rungs 3–7 exist as builders (`build_restore_database`, `build_restore_chain`, `build_install_node`).
- **New:** rungs 1–2 as builders (restart php-fpm / postgres / container / agent; reclaim disk; renew certificate; finish a half-applied upgrade), plus the escalating forced-reboot primitives of §4.5. Precondition and verification wrappers around every rung, including the existing ones — a builder today assumes an operator judged the situation; a rung must judge for itself.
- **The real cost is empirical, not code.** Each entry has to be proven against a deliberately broken box, and the library grows for as long as the product lives. This is the thing customers are actually buying, and the only component whose value compounds. In service posture it compounds *fleet-wide*: one customer's novel failure becomes everyone's runbook entry at the next release.

### B. Incident record  ·  *medium*

One object per event: detection and vantages, each attempt and its outcome, why the driver advanced, resolution, data window lost.

- **Reuses:** `mjb_management_jobs` already holds the attempts.
- **New:** the data class grouping them (owner-scoped, §12.1), plus **two** renderings — an operator view and an owner-facing plain-language timeline. The second is the sale (§10), and it is not the first with different CSS.

### C. Ladder driver  ·  *small–medium*

The scheduled task that turns a confirmed down-transition into dispatched repairs, holds the clock, and advances rungs.

- **Reuses:** `FleetBackupRun`'s exact shape — select due work, build steps, `ManagementJob::createJob()`, concurrency guard on `pending`/`running`. `RunNodeUptimeChecks` already produces the transitions.
- **New:** the wire between them, the per-incident clock, rung advancement, per-node consent-tier enforcement, and **channel routing** (§6): rungs 1–2 dispatch to the node agent's vocabulary queue; destructive rungs enter a **staged-awaiting-owner** state — evidence, snapshot, and chain point prepared — until an unlock window opens.

### D. Guard rails  ·  *medium, and subtly hard*

Multi-vantage confirmation; correlated-failure suppression (plane-suspects-itself, §7.2); pave-rate cap (which also protects Let's Encrypt duplicate-certificate limits); the restore-check gate; escalation classes.

- **Reuses:** `NodeMonitorHealth`'s inconclusive/stale/misconfigured modelling — the hard-won half.
- **New:** everything that only matters once software decides instead of an operator. **This is where the bugs will live.** Small in lines, large in care.

### E. Evidence bundle + redaction  ·  *medium — now v1 work (§11)*

Gather the right diagnostic signal off a broken box, strip personal data, structure it for a model.

- **Reuses:** `SmSecretRedactor` as the pattern.
- **New:** a personal-data pass (today's redactor covers secrets only), the collection set per failure class, and a size discipline — a model reasons worse over an unfiltered log dump, so this is a quality problem before it is a privacy one.
- **Collectors are the diagnostic menu (§5, §13.12):** each is a named, parameterless-or-bounded read (service states, journal tail, disk/memory, error-log extract, DB connectivity, config syntax, cert status, upgrade state, …) with a known output shape the redactor is written against. Collectors execute as vocabulary-channel jobs on the node agent (§6) — no SSH involved. The redaction pass moves to v1 with the service posture; the *model-facing* structuring can still wait for F.

### F. AI selection layer  ·  *small — genuinely*

Given the bundle and the permitted rungs, choose one, supply parameters, justify it.

- **Reuses:** `ActionRegistry` + `_logic_descriptor()` declarations, `DescribeActionsTool` against a recipe's `rcp_allowed_actions` allowlist, `InvokeActionTool` for validated invocation, `CostGuard` for spend ceilings, the existing recipe/executor supervision path.
- **New:** the rung descriptors, one recipe definition, and the prompt.
- **It stays small only if the menu discipline holds.** The driver executes; the model selects. No tool in the recipe's allowlist may accept a free-form command.
- **Evidence is untrusted input — including to the model.** Collector output comes off a possibly-compromised machine, so log lines are a prompt-injection channel into rung selection. The menu discipline *is* the containment: an injected instruction can at worst sway the choice among permitted rungs, within that node's consent tier, on the attacker's own already-compromised node — it cannot name a new action, touch another node, or widen the allowlist. Nothing derived from evidence may ever modify the recipe's allowed actions.

### G. Database restore check  ·  *small, and it gates everything above rung 2*

On a schedule, on each guarded node: restore the newest backup dump into a throwaway database name, assert it loads and row counts are sane, drop it (§9, §13.9).

- **Reuses:** the chain-open machinery (`backup_site_key` on the node) and the dump artifacts already on disk or on the target.
- **New:** one small builder, the disk-space preflight (skip-and-report below headroom — no restore script has a free-space check today), trap-based cleanup on every exit path, the orphan sweep at run start, and the scheduler + result record.

### H. Packaging  ·  *small–medium — box posture, after the service works*

- **Reuses:** `install_bundles.json` is the existing install-profile hook — a `sentinel` bundle (`["server_manager", "joinery_ai", "sentinel"]`) is a one-line JSON entry consumed by the existing `install_bundle.php` step.
- **New:** the bundle entry, the agent-liveness self-heartbeat of §7.3 (shared with the plane), and the provisioning profile the box sale is made from. The licensing sentence + test pin (§13.8) land here.
- **Deliberately not built:** a headless "no public site" mode. A guardian that serves a stock login page is boring enough.

### I. Feed client + escalation sender  ·  *small — later phase, box posture only*

The plane updates with releases and pages through existing alert paths, so it needs neither. A live runbook feed and outbound escalation POST for **box** sentinels remain later-phase, on the `MarketplaceClient` signed-catalog + `SafeHttpClient` patterns, outbound-only.

### J. Sealed per-node credentials + unlock flow  ·  *medium — gates the first external customer*

The §6 sealed-key channel, which does not exist in any form today (the fleet shares one provisioning key on disk).

- **Reuses:** the Sealed Vault (identity, seal-to, unlock window — `docs/sealed_vault.md`); the agent's existing in-memory credential placeholder pattern (`__SM_CREDS__`) for handing the plane-side executor a decrypted key that never touches disk.
- **New:** the **O10 approval flow** — approval-passkey registration, the node-issued job-binding challenge, agent-side WebAuthn verification, the approval screen (staged incident + sign-to-run, carrying the live §13.10 snapshot question when it applies, served on the node's own admin whenever it is reachable), and the per-opening notification; per-node key minting with immediate seal-to-customer (plaintext never at rest) for the rung 6–7 channel, the restricted support account and its sudo allowlist, destructive-channel verification at each unlock (§12.2 step 6), rotation, and the customer-facing local revoke of §12.2.

### N. Agent node posture — the vocabulary channel  ·  *built by the prerequisite spec*

The §6 unattended channel **is `specs/agent_on_node_architecture.md`**, built fleet-first as this spec's prerequisite: the agent's remote job source and pairing, the compiled-in primitive vocabulary with node-side refusal, primitive classes, and the per-node acceptance policy — which that spec ships **uniform fleet-wide** (its A1/A2: no `exec` class exists at all, and `destructive` is refused unattended everywhere, so the sealed-SSH channel of §6 is the only destructive path on every node, not just guarded ones). What remains sentinel-specific here: result posting into the incident record rather than the operator job log.

### K. Sentinel plugin: tenancy + customer surface  ·  *medium — new with the service posture*

- **Reuses:** plugin view auto-discovery (`/profile/sentinel/…`), the admin-page patterns, the API action surface (`_logic_descriptor()`), `sbt_subscription_tiers.sbt_features` for gating (the ScrollDaddy mold).
- **New:** the node-relationship data class with account ownership, owner-scoping on every customer view and API action, the coverage dashboard (§10), the free/paid remediation gate and seat accounting (O2), and the plane-operator admin views distinct from Server Manager's.

### L. Enrollment — both halves  ·  *medium*

- **Reuses:** the `MarketplaceClient` outbound-fetch pattern; `SafeHttpClient`; FormWriter for the consent screens.
- **New:** enrollment-code issuance and validation (plane), the node-side enrollment flow of §12.2 (display-then-apply of the support-account spec, account/key/allowlist provisioning, the §4.5 self-recovery arming, relationship record), the heartbeat-check and probe tasks (§12.3), the local revoke, and the plane-side "release node."

### M. Billing integration  ·  *small–medium*

- **Reuses:** the store plugin's subscription-tier billing wholesale — products, checkout, tiers, `sbt_features`.
- **New:** the sentinel plan product (one paid plan, per-node — O2), seat accounting (purchased seats vs enrolled nodes), lapse handling (free-tier drop + storage grace window with visible deletion date — O8), and per-customer storage bucket provisioning with object-lock retention, the scheduled delete-refusal check, and usage accounting (O5).

---

## 15. Order, by dependency

0. **`specs/agent_on_node_architecture.md`, complete through its Phase 3** (owner-set prerequisite). Steps 1–4 below then run over the vocabulary channel on our own fleet — which is itself the burn-in that spec's primitives need.
1. **B + C, rungs 1–2 only, on our own fleet.** Teach `RunNodeUptimeChecks` to open an incident on a confirmed down-transition; add the driver task. No restores, no AI, no customers — a fixed rung 1→2 sequence against the fleet that exists today. Proves detection, clock, escalation, and the record against the least dangerous repairs.
2. **G.** Independent of everything above, and a prerequisite for claiming anything about restores. The disk preflight and cleanup guarantees are not optional trim; they are the component.
3. **D.** Before any destructive rung is reachable.
4. **J.** The sealed-key channel — needed here, not just before external customers: per the migration spec's A2, **no node accepts destructive primitives unattended, own fleet included**, so rungs 3–5 cannot even be tested without the owner-present channel. No customer key ever exists unsealed. (N is already done — step 0 — and the shared fleet key is already destroyed by that spec's Phase 3.)
5. **A rungs 3–5**, behind G's gate — proven **owner-present** on our own fleet, which is exactly how customers will run them in service posture.
6. **K + L + M, and the O3 plane.** Tenancy, enrollment, billing, deployment — the service goes live to first customers with the deterministic ladder.
7. **E, then F.** The AI arrives *after* the ladder already works in production without it — so its failure mode is a worse rung choice, never an unrecovered site. (E's redaction pass lands with 6 if any diagnostics are stored plane-side before F.)
8. **H — box posture packaging.** The power-user product ships once the service has burned in the same code.
9. **A rungs 6–7** in service posture per O7 (human-driven ceremony, plane drives rung 6 on fresh ground); box posture provider-credential class per §13.4.

**Steps 1 and 2 are worth building even if the product is never sold.** An incident record and proven restores improve the fleet that exists today, on a single box, with no customers and no subscription.

---

## Appendix A — The failure and incident matrix (owner-reviewed 2026-08-25)

The complete enumeration of what can go wrong under both specs as designed, what the response is, and whether it is ultimately fixable. This is the working companion to the promise boundary (`specs/agent_on_node_architecture.md` §3.7): that table states what an attacker gets; this one walks every case. Customer-facing copy derives from these two together. **Maintenance rule:** a failure discovered in the field that is not on this matrix gets added when its runbook entry is written — the matrix and the rung library grow together.

### A.1 Operational failures

| # | Failure | Response | Fixable? |
|---|---|---|---|
| 1 | Service crash (php-fpm, postgres, container, agent) | Rung 1 restart, unattended, minutes | Fully, no data loss |
| 2 | Disk full | Rung 2 reclaim, unattended | Fully |
| 3 | Certificate expired | Rung 2 renew, unattended | Fully |
| 4 | Half-applied upgrade (the known two-pass wedge) | Rung 2 finish-upgrade, unattended | Fully |
| 5 | OOM lockup / kernel panic / hang | Self-recovery armed at enrollment (§4.5): watchdog + panic sysctls reboot the box; while SSH answers, escalating forced reboots up to sysrq-b | Fully, unattended |
| 6 | Hypervisor-level freeze | Guided ceremony (§4.5): one page to the owner — reboot from your provider console — and the ladder resumes automatically when SSH answers | Fully, costs the owner minutes of presence |
| 7 | Poisoned state (bad migration, plugin crash-on-boot, poisoned setting) | Rungs 3–5, staged unattended, run only on the owner's node-verified approval (§6); rung 5 walks the chain back until it converges | Fully, with loss back to the newest good run — this branch can never be near-zero, by design (§3) |
| 8 | Environment wrecked, state good (filesystem/code corruption) | Fresh pre-destructive snapshot → rung 4 or 6 → current data restored onto clean ground | Fully, near-zero loss — if postgres is up for the snapshot (§4.4) |
| 9 | Postgres dead **and** a restore is needed | The §4.4 snapshot gate stalls; the §13.10 consented skip applies only where nothing could have been captured anyway, else an owner ceremony | Fixable, but the near-zero branch is forfeited — falls to last-run loss |
| 10 | Machine destroyed (provider deleted it, hardware death) | Rung 7, human-driven in service v1 (O7); requires the owner's recovery key — the wiped box's `backup_site_key` died with it (§4.3) | Fixable to last scheduled run, owner-present, key ceremony required |
| 11 | Backup chain quietly unrestorable | The restore check (§9) exists precisely for this — "not proven" on the dashboard within a day, long before an incident needs it | Preventable, not fixable after the fact; mid-incident, rung 5 walks to an older chain point |
| 12 | Detector wrong (false-down flood, resolver break, plane network fault) | Multi-vantage rule + correlated-failure suppression + "detector is wrong" as a closeable diagnosis (§7.2) | Fully — the correct response is nothing |
| 13 | Site up but *wrong* (serving garbage, monitoring green) | Not probe-detectable; the ticket is the fallback trigger (§7.1) | Fixable once a human notices — an honest gap in detection, not remediation |
| 14 | The guardian itself dies | Guarded nodes check the plane's heartbeat and surface it in their own admin (§7.3); plane recovery is getjoinery ops' runbook | Fully; the node never depended on the plane to run, only to be rescued |
| 15 | Fleet-wide correlated real outage (provider region down) | Suppression trips — the ladder deliberately stands down; ops handles it manually | Fixable at human speed; the suppression that blocks false floods also mutes real mass events. Accepted |
| 16 | Pave loop / repeated rung failure | Same-rung-twice and pave-rate caps escalate immediately (§8; also protects Let's Encrypt duplicate limits) | The loop is always breakable; the novel failure becomes a runbook entry |
| 17 | Subscription lapses | Free-tier drop, 60–90 day storage grace with visible deletion date (O8) | Fully reversible inside the window; after it, plane-stored backups are genuinely gone — stated at signup |
| 18 | Owner never answers the approval page | Site stays at whatever rungs 1–2 achieved | **Not fixable, by explicit design** — destructive-waits-for-you is the promise; its price is that absence = downtime |

### A.2 Security incidents, by what the attacker holds

| # | Attacker holds | Gets / doesn't get | Response | Fixable? |
|---|---|---|---|---|
| 1 | **The plane, web-level** | Metadata, redacted excerpts, nuisance observe/operate jobs. Not: mail, backups, backup deletion (object-lock), destructive actions, any stealable credential — it stores only verifiers and sealed blobs | Rebuild the minimal dedicated plane, rotate pairing credentials | Fully — the plane was made not worth attacking |
| 2 | **The plane, persistent (tampered pages)** | The O10 residual only: during a **live ceremony**, swap which staged action gets signed, or receive a rung 6–7 key in memory — one node, that moment, notifications firing; sshd off means the key alone opens nothing | Ops monitoring, rebuild, rotate, notify affected customers | Recoverable; residual bounded and loud, not eliminated |
| 3 | **A node's web tier** (the common breach) | That site's unsealed data. Not: sealed content, the root-owned agent or approval keys, quiet persistence — attestation flags modified files and dropped webshells in minutes; unlock pages are static-code-only | "Compromise, not failure" escalates immediately (§8); owner-approved restore to a pre-compromise chain point, reversible via rollback snapshots | Recoverable to a point in time. Not undoable: disclosure of unsealed data, and any unlock inside the detection window |
| 4 | **A node's root** | That node entirely; can falsify its own attestation. Not: any other node (pairing credential claims only its own jobs), the plane, off-site backup deletion inside the lock window. Detection honestly weak (volume anomalies, behavior) | Rung 7 from clean ground, restore pre-compromise. This is also the ransomware answer, and it is a strong one | Recoverable as infrastructure; disclosure of that node's contents is not. "Root = game over for that node" is stated, not rounded up |
| 5 | **A hostile node, attacking upward** | Prompt-injection via collector output — contained to rung choice, in-tier, on its own node (§14.F); hardened claim/result endpoints | Contained by construction; a real plane RCE through this path collapses to rows 1–2 | Contained |
| 6 | **The customer's device / passkey / vault credential** | That customer's approvals and unlocks: could approve a staged restore (reversible) or open an SSH window on their node — every opening notifies out-of-band. Not: any other customer, backup deletion | Local one-button revoke on the node (works with the plane hostile or gone), re-enroll, new passkey | Recoverable; bounded to one customer by construction |
| 7 | **Backup storage** | Reading: impossible (sealed). Deleting: refused inside object-lock, proven by a standing scheduled check. Residuals: an attacker patient beyond the retention window; the storage provider itself dying, since v1 has one target per node | Time-bounds are stated bounds; the single-target residual is the one backup risk not yet closed — a second target / BYO mirror is the eventual answer | Bounded, with one named open residual |
| 8 | **The getjoinery probe endpoint** | Falsely confirm a down-transition — which still cannot pass the owner-approval gate | Nuisance | Bounded |
| 9 | **The release-signing key / the publisher** | Everything, every updating node, one update cycle — agent, attestation, and vocabulary are only as honest as the signature. No architectural defense; custody (§3.5.5, currently unmet — the migration spec's Phase 0 carries the fix) is the entire mitigation | Out-of-band key rotation and manual fleet recovery — a disaster plan, not a feature | **Not fixable by design. The root of trust** |
| 10 | **An insider at getjoinery** | Ops staff = rows 1–2 (contained); the signing-key custodian = row 9 (total) | Keep the second group as small as key custody allows | Maps to the rows above |

### A.3 Where the promise ends

The §3.7 no-rings rule makes this line stable, not provisional: a future mechanism must eliminate a row or shrink a cell, never shuffle trust.

- **The promise holds in full** for every environment failure, every state failure with backups proven, plane compromise of any depth, node compromise below root, and any deletion attempt inside the object-lock window — nearly everything that will actually happen.
- **The promise degrades but stays bounded** at: node root (infrastructure recoverable, disclosure not), the live-ceremony swap residual, unlocks inside the attestation window, and correlated real outages (manual speed). Each carries its bound in the tables above.
- **The promise ends at exactly four places:**
  1. **The signing key.** One update cycle to total loss; custody is the only defense, and there is no fifth layer to build.
  2. **The customer's recovery key, lost, after rungs 6–7 destroy the node's own key.** Backups sealed forever. Escrow is closed permanently and deliberately (§13.7) — this failure is the price of "we cannot read your backups." All mitigation is upstream: the enrollment ceremony, the proven-key panel, the restore check.
  3. **The customer's absence.** No approval, no restore. "We can't act destructively without you" necessarily means "you must show up" — the sovereignty trade the product is built on, said plainly in the copy.
  4. **Patience beyond the retention window.** Object-lock plus grace are honest time-bounds, not infinite ones.

The pattern, and the design's actual claim: **every remaining unfixable failure requires either the root of trust or the customer's own half of the bargain.** Nothing in the ends-here column belongs to the plane, the network, or an attacker of any node.
