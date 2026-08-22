# Sentinel — Managed Recovery for Joinery Nodes

**Status:** **READY FOR BUILD — 2026-08-22, unbuilt.** Drafted, verified against the code, and revised the same day; all twelve §13 decisions resolved with the owner. **v1 scope: the box only** — no subscription, no hosted service, nothing recurring (§13.1). The remediation machinery this spec assembles already exists (`JobCommandBuilder` builders, `reconcile_site.sh`, backup chains, `NodeMonitorHealth`). What is new is the **incident record**, the **ladder driver**, the **database restore check**, the **per-node credential class**, and the **sentinel** packaging. Build order is §15.

**Origin:** Owner feedback that prospective self-hosters fear their Joinery going down with no skills to fix it. Two shapes were considered — a subscription service run from getjoinery's control plane, and a customer-owned provisioned box. §2 concludes they are **layers, not alternatives**.

---

## 1. The product in one paragraph

When someone's Joinery breaks, a machine they own notices within minutes, tries the cheap fixes first, and — if those fail — rebuilds the site from its own backups onto clean ground. It works through a fixed list of repairs, not by improvising, and every step it takes is written down where the owner can read it. If the machine runs out of list, it stops and fetches a human. The promise is not "your site will never go down." The promise is **"your site comes back, working, and you lose no more than X of data."**

That promise is possible because the worst case is bounded. Diagnosis has no upper time limit; **wipe and restore** does. The AI gets a fixed budget to be clever, then a deterministic path takes over. The guarantee is not "our AI is smart" — it is "our worst case terminates."

---

## 2. Two products, one stack

### 2.1 The Sentinel box

A **sentinel** is a small Joinery install whose only job is watching and repairing other nodes. It is a control plane with no public site: Server Manager, the Go agent, the job queue, the backup catalogue, and the incident record. It holds the credentials to the nodes it guards, and those credentials never leave the customer's ownership.

This is the sovereignty-consistent shape and it should be the default sale. It dissolves the three hardest objections to the service model at once:

| Objection to a hosted service | Answered by the sentinel |
|---|---|
| "Why would I give you root on my server?" | You don't. The credentials sit on your box. |
| "One breach of your support plane exposes every customer." | There is no shared plane. A sentinel holds one customer's keys. |
| "Your AI reads my error logs, which contain my members' data." | The model runs where the customer chooses, including locally. |

It also fits the existing commercial pattern — a provisioned box sold once, like the automatic install and the Linode one-click, rather than a recurring claim on the customer's infrastructure.

### 2.2 The subscription

Two things a sentinel cannot do for itself:

1. **Stay current.** The value is the runbook library — the enumerated repairs, their preconditions, their verifications. New failure modes are learned from the fleet. A sentinel cut off from that feed keeps working with what it has but stops learning.
2. **Page a human.** A machine can decide it is out of options. It cannot conjure someone to call. Human escalation is inherently a relationship, therefore inherently a service.

### 2.2b A sentinel is Server Manager with autonomy, on a second box

**Almost none of this is a new system.** Server Manager already holds the managed-node registry, runs the agent and job queue, owns every rung of §4 as a builder, and runs scheduled tasks against the fleet. One topology fact matters everywhere below: **the Go agent runs on the control plane, polling the control plane's own database, and executes on nodes over SSH. Managed nodes run no Joinery software at all — they are SSH targets.** Two existing tasks bracket the entire gap:

- **`RunNodeUptimeChecks`** already probes each node on its interval, computes up→down and down→up **transitions**, alerts once per transition without re-alerting while down, and — from the false-down flood — already records a probe that died in the monitoring host's own resolver as **inconclusive rather than down**. It detects correctly. It then only sends an email. *It reports; it does not act.*
- **`FleetBackupRun`** already does the acting half for a different purpose: on a schedule it selects due nodes, calls `JobCommandBuilder::build_backup_run()`, and dispatches with `ManagementJob::createJob()`, under a concurrency guard counting `pending`/`running` jobs.

So the pattern *scheduled task → build steps → create job → agent executes* is built, shipped, and proven in production. **The sentinel is the wire between those two tasks**, plus the record that makes it legible and the guards that make it safe.

What is genuinely new:

| New | Existing it is built from |
|---|---|
| Ladder driver (consumes down-transitions, dispatches repairs) | `FleetBackupRun`'s dispatch shape, exactly |
| Incident record (detection → attempts → escalation → outcome) | `mjb_management_jobs` holds the attempts; the narrative grouping them does not exist |
| Database restore check (§9 — throwaway DB name, disk preflight, guaranteed cleanup) | the chain-open machinery + dump artifacts already on the node |
| Escalation + runbook feed (§8 — outbound pull/post, not Direct) | `MarketplaceClient`'s catalog-fetch pattern |
| Per-node support credentials (§6 — minted key, restricted account, sudo allowlist, revoke UI) | nothing — today the fleet shares **one** provisioning key, stored as a path on the control plane's disk |
| Multi-vantage gate, rung caps, restore-check gate, correlated-failure suppression | new, and only necessary once software decides rather than an operator |

**The one requirement beyond "a second VPS": the sentinel must be a *minimal* install.** Today Server Manager typically runs on a control plane that is also a real site. That is fine for an operator's remote control; it is wrong for a guardian, because a sentinel also running mail and a store shares a failure surface with the thing it is watching. A sentinel's whole value is being more boring than its charges.

**The trust posture changes even though the code does not.** Today a human clicks Restore and owns the consequence. Under this spec, software decides. That single difference is what every guard rail in §7 and §8 exists to earn — they are not padding, they are the price of removing the operator from the loop.

### 2.3 Therefore: the box is the product, the feed and the human are the subscription

This is the same open-core split already in use. Stated as a rule for build:

- **A sentinel with no subscription never degrades.** It keeps its runbooks, keeps watching, keeps repairing, keeps running its restore checks. It does not nag, phone home, or hold function hostage. Anything else contradicts the platform's stated business model and would be read as a rug-pull by exactly the audience being sold to.
- **The subscription is a later phase in its entirety (§13.1).** When it arrives it adds the live runbook feed, named human escalation, and the getjoinery probe endpoint as an outside vantage (§7.2). In v1, runbook entries ship with platform releases and escalation pages the owner. The second vantage is never subscription-gated in any phase: guarded nodes supply it to each other (§13.6).

**Commercially this is a tier of a plugin already sold, not a new SKU.** The sale becomes "a second small VPS, provisioned, running a minimal Joinery with Server Manager in sentinel posture" — which fits the existing provisioned-install commerce rather than needing its own product line. One licensing decision falls out and is listed at §13.8: **a box whose only job is guarding another box should not require a second licence.**

### 2.4 The guarantee splits along the same seam

This is the sharpest consequence and it inverts the usual SLA shape:

- **The machine guarantees resolution.** Bounded ladder, measured restore checks, published record. This requires no trust — it is verifiable by the owner from their own dashboard.
- **The service guarantees response.** A human answers within a stated window. (Later phase — §13.1. In v1 the "human fetched" is the owner, and no response window is promised by anyone.)

You cannot write an uptime SLA over infrastructure you do not control, and should not try. What you can do is publish measured facts and let the record be the guarantee. That is the site's existing **"verify, don't trust"** register (see `specs/getjoinery_site_redesign.md` §3), applied to operations.

---

## 3. The guarantee, stated precisely

> **Your site back and working, losing no more than X of data, within Y of confirmed detection.**

A **data-loss guarantee, not an uptime guarantee.** Restore returns a site to a point in time, not to now.

**X is a function of the failure class, not just reachability, and all three branches are stated to the customer:**

| Situation | Data loss |
|---|---|
| Environment broken, state good — resolved at rungs 1–2 or 6–7 | **Near zero** — a fresh backup is taken *before* any destructive rung, and the current data is restored onto clean ground |
| The fault lives *in* the state — resolved at rungs 3–5 | Back to the **newest good run**. This branch cannot be "near zero" even on a fully reachable node: a fresh backup of a poisoned state restores the poison, so these rungs deliberately go back |
| Node hard-down / unreachable | Back to the **last scheduled run** |

The near-zero branch also carries a precondition: the fresh pre-destructive backup needs PostgreSQL up, credentials valid, and free disk (§4.4). A node broken in exactly those ways falls to the next branch.

Y, likewise, is not one number: rungs 1–5 run unattended on the machine's clock, while rungs 6–7 wait on a human (§4.3). Customer-facing copy must state the unattended window and the escalated window separately rather than averaging them into a figure that is true of neither.

X is therefore a function of backup frequency, which is the natural upsell dial. The two-branch shape is what makes the number defensible rather than aspirational.

**One honest footnote to carry into customer-facing copy:** within a single run the file archive and the database dump are taken minutes apart, so a backup has no single point-in-time (`docs/backups.md`, "How chains work"). For a web tree this is the normal trade, but the guarantee language must not imply transactional consistency across the two.

---

## 4. The remediation ladder

Every rung already exists as a job builder. The ladder is the product; the driver is the new code.

| Rung | Action | Existing mechanism | Disruption |
|---|---|---|---|
| 1 | Restart services (php-fpm, postgres, container, agent) | new builders, trivial | none |
| 2 | Reclaim disk, renew certificate, finish a half-applied upgrade | new builders | none |
| 3 | Restore database only | `build_restore_database` | data to last run |
| 4 | Restore newest chain point (files + database) | `build_restore_chain` | data to last run |
| 5 | Walk the chain back — `--seq N` | `build_restore_chain` with `--seq` | data to an older run |
| 6 | Reinstall Joinery in place, then restore | `build_install_node` + restore | full site rebuild |
| 7 | Provider rebuild → full install → restore | provider API + rung 6 | whole machine |

**Rung 2 earns its place from real history.** The upgrade two-pass behaviour is a known, recurring, diagnosable outage on this platform. It belongs in the library as a first-class named repair, not as something an AI rediscovers each time.

**Rung 5 matters more than it looks.** If the cause lives *in* the restored state — a bad migration, a poisoned setting, a plugin that crashes on boot — then restoring the newest point restores the cause. Walking the chain back is the escape, and because each step trades data for certainty, **it converges**. That is the difference between a procedure and a loop.

**Rung 7 is the only rung requiring a destructive provider credential**, which is by far the largest consent ask. Rungs 1–6 need only SSH. Price and gate them separately: a customer who declines provider access still gets everything except "the OS is toast" and "the box will not answer."

### 4.1 Rollback is already free

Restores leave `auto_pre_*` rollback snapshots behind. This has a second-order benefit worth designing around: **the AI can safely attempt riskier repairs than it otherwise could**, because every attempt is reversible. The ladder is not just a fallback — it is what makes the earlier rungs affordable.

### 4.2 Restore is genuinely unattended — through rung 5

Confirmed from `docs/backups.md`, and load-bearing for this whole spec:

- `config/backup_site_key` exists specifically so that "pre-restore rollback snapshots and routine restores need no operator." **No recovery key, no customer, no ceremony** on the routine path.
- `reconcile_site.sh` makes a restored site agree with the machine it lands on, including **across shapes** (container ↔ bare metal), and always regenerates serving config rather than copying it.
- The target's own `config/Globalvars_site.php` and `config/backup_site_key` **survive every restore** — the thing that otherwise turns a clean-looking rebuild into `SQLSTATE[08006]`.
- TLS resolves itself: the reconcile arms `joinery-ssl-retry@{domain}.timer`, the site serves HTTP until DNS resolves, then issues once. **Restore now, cut DNS later, certificate arrives on its own.**
- Restores replay **deletions**, so a restore is a true point-in-time and not an additive merge.

### 4.3 The key-custody discontinuity at rungs 6–7

**This is the real constraint on the guarantee, and it is a hard one.**

A chain's data key is recovered **on the node, from the node's own `config/backup_site_key`** — `build_restore_chain` says so in as many words, and adds that "the control plane's recovery private key never travels." That is what makes rungs 3–5 unattended.

Rungs 6 and 7 destroy that key. A wiped or rebuilt machine mints a *fresh* `backup_site_key`, and a fresh key does not open a chain sealed to the old one. The restore path already handles this explicitly, and it hands off to a human:

> `This node has no backup_site_key, so it cannot open its own chain.`
> `Recover the key with backup_envelope.php open --sidecar manifest.json --private <recovery key> and restore from a shell.`

The recovery private key lives in a password manager and never touches a server, by design. So **the ladder is unattended through rung 5 and requires a human at rungs 6–7.**

Four ways to resolve this, and the choice is a real one (§13.7):

1. **Accept it, password-manager ceremony.** Rungs 6–7 are break-glass by nature — a machine rebuild deserves a human anyway (§8 already escalates on rung 7). The owner is paged, retrieves the recovery key from their password manager, and completes the restore from a shell (or pastes the key into a one-shot ceremony the sentinel presents and never stores). Costs nothing new to build.
2. **Adopt `specs/managed_backup_recovery.md`.** The companion spec already designs multi-wrapped custody of *this exact key* — passphrase + server-gated wrap, passkey-PRF wrappings, stored beside the backups. The owner proves a credential they already have and the key is recovered even if the password manager is gone. It survives sentinel loss (the wrapped blob lives with the backups, not on the sentinel) and getjoinery still cannot open it unilaterally.
3. **Escrow the site key on the sentinel.** Makes rungs 6–7 fully unattended, but the sentinel then holds material that opens that node's archives — a genuine custody change that destroys the "the sentinel holds no key that reads your backups" property. Sealing it to the owner's passkey on the sentinel does not rescue this: sealing is per-instance, so the copy is unreachable exactly when the sentinel is the box that died.
4. **Pre-seed the replacement.** Write the old site key onto the fresh machine before restoring. Same custody question as (3), since the sentinel must have held a copy to write it.

**Resolved (§13.7): (1) now, with (2) as a committed dependency of the full guarantee; (3) and (4) closed permanently.** Both surviving options preserve the strongest property of this design — a sentinel can drive a full recovery without ever holding a key that decrypts a customer's backups — and both put the human exactly where §2.4 already says the human belongs.

### 4.4 The pre-destructive snapshot is a hard gate

Every destructive rung's first step is a snapshot — `pg_dump` piped to the node's `/backups`, plus a tar of the tree on the project path — and **if any pre-backup step fails, the destructive steps never run** (the job aborts). The snapshot needs PostgreSQL up, credentials readable from `config/Globalvars_site.php`, and free disk; there is no free-space check anywhere in the restore scripts. So a node whose postgres is dead or whose disk is full stalls at rung 3/4's own safety gate — exactly the failure modes those rungs exist for. Rung 2 (reclaim disk) clears one branch; postgres-dead has no path through. Whether the driver stalls-and-escalates or may take a consented skip-with-loss decision is §13.10.

These snapshots are also **plaintext by design** (rollback artifacts on local disk, swept by local retention) — see §6 for the confidentiality scope.

What the driver does when the gate refuses is an owner decision made at enrollment, not incident time — resolved at §13.10: consented skip only where the snapshot could not have captured anything anyway, stall-and-escalate otherwise, default stall.

---

## 5. The AI drives, but it does not hold the wheel

**The AI never authors a command string.** It selects a rung and supplies parameters; the server builds the steps. This is already how `JobCommandBuilder` works and the driver must not add an escape hatch.

Each rung is a runbook-as-data — **precondition, action, verification, rollback** — matching the executor-supervision pattern already used elsewhere in the platform.

**The clock, not the cleverness, is the guarantee.** The driver holds a budget. While the budget lasts, the AI may diagnose, gather evidence, and choose among low rungs. When it expires, the ladder advances on its own. An AI that wants an action not in the library does not get it — that request is an **escalation trigger** (§8), and a signal that the library needs a new entry.

**The same discipline applies to reads.** Diagnosis is menu-driven too: the AI chooses among named evidence **collectors** (§14.E), never a free-form shell read. This is load-bearing for §11, not just §5 — a redaction pass is only writable over output whose shape is known, and a free-form read is an exfiltration channel into the model context for exactly the data the sentinel promises stays home. The cost is small because collectors are reads: cheap to add, no side effects, so the diagnostic menu can be far larger and grow far faster than the repair menu. A collector the AI wanted and did not have is the same library-gap signal as a missing rung.

**Allowlist enforcement belongs on the node, not only on the sentinel.** Managed nodes run no agent — they are SSH targets — so the only node-side enforcement point is the support account itself: a restricted user whose sudo allowlist admits exactly the rung vocabulary and nothing else (§6). That construct does not exist today and is new work. The platform's posture here has precedent — the control-plane agent refuses to install a binary the publisher did not sign, because the web tree is writable by the web user while the agent runs as root — but that check protects the sentinel's own binary; the node-side equivalent is OS-level. A compromised sentinel must not be able to talk a node into arbitrary work.

---

## 6. Credentials

Three classes. Under the sentinel model, custody of all three sits with the customer.

| Class | What it is | Custody | Used by |
|---|---|---|---|
| **Standing support key** | Unique-per-node SSH key, restricted account, sudo allowlist | Sentinel, SecretBox-encrypted at rest | The driver, unattended, rungs 1–5 (and rung 6's SSH work; the chain-open at 6–7 waits on the human of §4.3) |
| **Break-glass access** | The provider's rescue boot from the customer's own account — nothing minted, nothing stored (§13.11) | Customer's provider account; never the sentinel | A human, following the ceremony doc |
| **Provider API token** | Power and rebuild actions | Sentinel, scoped as narrowly as the provider allows | Rung 7, and reboot-when-unreachable |

**This entire class is new work, and §14.J costs it.** Today the fleet authenticates with **one shared provisioning keypair**, stored as a plaintext path on the control plane's disk (`mgn_ssh_key_path`); there are no per-node keys, no restricted account, no sudo allowlist, and the agent cannot use a key that is not sitting unencrypted on disk (no passphrase support). SecretBox in Server Manager today covers backup-target credentials and OAuth tokens — not SSH. Minting, deploying, rotating, and revoking per-node keys, provisioning the restricted account with its allowlist, and teaching the agent to receive key material in memory (the existing `__SM_CREDS__` placeholder pattern) are all part of this spec's build.

**Why not simply store the root password.** It is not revocable without the customer's help, cannot be scoped, is very often reused elsewhere, and the fleet already authenticates with keys. A minted, per-node, revocable key is strictly better on every axis. **Note also that a storable break-glass root does not exist on the current fleet** — the installer disables root SSH, and provider-provisioned nodes get a random root password that is deliberately never stored. Break-glass may therefore not be a stored credential at all but the customer's own provider console; that choice is §13.11.

**Provider tokens are rarely scopeable.** On major providers (Linode included) an API token is account-wide: a sentinel holding one can destroy every instance in the account, not just the guarded node. The consent copy for rung 7 must say this in plain words; "scoped as narrowly as the provider allows" often means "not at all."

**Why passkey-sealing cannot protect the working credential.** Sealed content is unreadable by unattended code — a hard platform invariant. A root password sealed behind the owner's passkey is unreadable at precisely the moment it is needed: node down, owner asleep. Passkey-sealing therefore protects **break-glass only**, where a human is present by definition. The unlock window shape already exists in the vault.

**Revocation is a customer-side control.** Every node's support key must be revocable from the owner's own admin, individually, without contacting anyone. This is the answer to "what if I stop trusting this," and it needs to be visible, not buried.

**Backup confidentiality is not a prerequisite — it is already closed.** Encryption is the default on every path, and the automated paths cannot produce a plaintext archive at all:

- `backup_project.sh` and `backup_files.sh` default to `ENCRYPT=true`. A run that cannot find a key **fails** rather than downgrading: *"Never silently downgrade to a plaintext archive: an automated run that cannot encrypt must fail, not quietly ship `config/` in the clear."*
- The chain path (`build_backup_run`) has no plaintext branch whatsoever — it always mints an envelope against the recovery public key.
- The two legacy ad-hoc builders can pass `--plaintext`, but only when an operator has explicitly unchecked encryption **and** the node has no cloud target, since a configured target force-sets `encryption = true`.

An unencrypted **archive that leaves the node** therefore requires a deliberate human act at a shell. Two local exceptions must be named rather than papered over: the `auto_pre_*` pre-restore snapshots of §4.4 are plaintext `pg_dump`/tar files on the node's own disk by design (rollback artifacts, swept by local retention, never uploaded), and the install-from-backup clone path passes `--plaintext` for its transfer. A ladder run multiplies the former; the confidentiality claim is scoped to what leaves the machine.

---

## 7. Detection

### 7.1 The incident opens itself

A down site cannot serve a ticket form, and waiting for the owner to notice burns the clock before it starts. Monitoring opens the incident. The ticket is the **fallback path for problems monitoring cannot see** (something works but is wrong), never the primary trigger.

### 7.2 The detector lies, and the platform already knows it

`NodeMonitorHealth` exists because monitoring breaks in ways that look like the fleet breaking. It already models `misconfigured`, `stale`, and `pending` as distinct from down, and `is_name_resolution_failure()` exists specifically because **a monitoring host whose resolver breaks fails every probe at once** — the false-down flood, on the record.

Rules that follow, non-negotiable:

- **No rung above 2 fires on a single vantage.** Confirmation must come from a second, independent observer.
- **"The detector is wrong" is a first-class diagnosis** the driver can reach and close on, with no repair attempted.
- **Correlated failure suppresses the ladder entirely.** If every node a sentinel watches goes down at once, the sentinel is the suspect, not the fleet.

Today every probe originates from the control plane alone — `RunNodeUptimeChecks` is single-vantage by construction, which is exactly why the resolver-failure carve-out exists. Resolved at §13.6: **guarded nodes vantage each other, as the default and the floor.** The minimum sale is already two machines, every guarded node is a full Joinery site able to run a probe task (the same node-side task the §7.3 heartbeat needs), and mutual probing needs no getjoinery dependency — so full-ladder capability never hangs on a subscription, which is §2.3's never-degrades rule kept honest. The getjoinery probe endpoint arrives in a later phase as the subscription's third, network-independent opinion and the tiebreaker for correlated failures where co-located customer nodes falsely confirm each other's death. Until then, a one-guarded-node customer supplies any URL-probe they control or accepts a rung-2 ceiling, and the dashboard says which in plain words.

### 7.3 Who watches the watcher

A sentinel is a box, and boxes go down. Four rules:

1. **A sentinel never guards itself.** The minimum viable deployment is two machines. Say this in the sales copy rather than discovering it during provisioning.
2. **A sentinel publishes a heartbeat** the guarded nodes can see, so a dead sentinel is visible from the thing it was supposed to protect — inverted from the usual direction, and the only arrangement that survives the sentinel dying quietly. **This is new node-side work, and the only node-side work in the spec:** a guarded node today has no knowledge that it *has* a sentinel, so this needs a relationship record on the node plus a small scheduled task that checks the sentinel's heartbeat URL and surfaces "your sentinel has not been heard from" in the node's own admin. Costed in §14.H.
3. **The heartbeat must attest the agent, not the web stack.** The sentinel's own agent process is its single point of failure: if it dies, every job sits `pending` forever and nothing complains. The heartbeat is derived from the agent's last job-claim/poll time, so a sentinel whose pages render but whose executor is dead reads as dead.
4. **A sentinel backs itself up** to the same targets it manages. Its state is a Postgres database and a folder, like everything else — but **its recovery is a manual, documented customer procedure**, not the ladder: nothing guards the sentinel (rule 1), and guarded nodes run no driver. A second sentinel (§13.6) is the only way to automate it, and the runbook for the manual path ships with the box.

---

## 8. Escalation

A timer alone escalates slow problems and misses dangerous ones. Escalate **immediately**, regardless of clock:

- The AI requests an action not in the library.
- The same rung has failed twice.
- Anything touching backups, keys, or deletion outside a rung's own defined actions.
- Any indication of compromise rather than failure.
- Rung 7 is reached (a machine rebuild should have a human's eyes on it, at least at first).
- The node has been paved more than N times in a rolling window. **This also protects against Let's Encrypt duplicate-certificate limits**, which a pave loop will otherwise burn, leaving the customer without HTTPS on a site that is otherwise fine.

The timer covers the remaining case: still grinding, nothing alarming.

**Transport: outbound only, in both directions of value.** In v1 escalation is owner notification through the existing alert paths, and runbook entries arrive with platform releases — no new transport at all (§13.1). The later-phase subscription adds the two outbound channels: the sentinel **polls** getjoinery for runbook updates — the same catalog-fetch shape `MarketplaceClient` already uses — and **posts** escalations outbound over HTTPS. Joinery Direct is explicitly not the transport: its own docs place machine-to-machine traffic on the machine channels, not the people-pipe; its entire receiving half (address resolvers, contact gate, signing-identity authority) is registered by the mailbox plugin, which a minimal sentinel does not run; and receiving requires a publicly exposed inbound endpoint — the opposite of a boring, unexposed guard box. Direct also has no feed or broadcast primitive, so a "runbook feed" over it would be N pushes to N publicly reachable boxes. Pull keeps the sentinel with zero inbound surface and gets authentication from the same signed-catalog pattern upgrades already trust.

---

## 9. The restore check is the investment

**An untested restore is a hope.** Current fleet state makes this concrete: retention deletion has never been live-tested, five nodes have no backup target, and the recovery key panel has an explicit `unproven` state. The platform already thinks in these terms — this spec makes it a scheduled obligation.

**The full clone rehearsal rig is deliberately not built (§13.9).** Restoring a complete copy of a customer site is restoring a *live* site — working Stripe and SMTP credentials in plaintext settings, a cron tick that fires within a minute — and caging it safely demands a quarantine mode, a third customer machine, and a consent story about where member data lands. The audience willing to run a third box for this rounds to zero; full-fidelity restore-path proof (files + reconcile + boot) happens instead at the **core level, on our own fleet, periodically**.

**Per-node proof is the database restore check.** On a schedule, the node restores its own newest backup dump into a throwaway *database name* — never over the live one — asserts it loads and row counts are sane, and drops it. No clone exists, nothing external is touched, no data leaves the machine. It converts "backups are landing" (already monitored) into "backups actually **open and load**," which catches the failure that matters most: a chain quietly unrestorable for months. Two hard requirements, both new code (no restore script today checks free space): a **disk-space preflight** that skips-and-reports "not proven — insufficient disk" rather than run without headroom, and **guaranteed cleanup** on every exit path — trap-based drop of the scratch database and temp files, plus an orphan sweep at run start for leftovers of a killed prior run. The check must never be the thing that fills a working box's disk.

**The check result is the coverage claim.** A node whose last check failed — or was skipped for disk — is **not proven**, and its dashboard says so in those words. "Your last backup opened and loaded 6 hours ago" is a measured fact, and a better trust artifact than any SLA paragraph.

---

## 10. What the customer sees

One **incident record** per event, readable without technical knowledge, containing: what was detected and from which vantages, what was tried, what each attempt did, why the driver moved to the next rung, what finally worked, and what data window was lost if any.

Standing dashboard state, always visible: last backup opened-and-loaded per node (§9), current data-loss window, monitoring health, which credentials are held and a revoke control for each.

**The record is the sale.** "Every action is on the record and you can revoke our access from your own admin at any moment" is the answer to "why would I let software do this to my server," and this architecture can actually back it up.

---

## 11. Privacy

Diagnosis means reading error logs, and those logs contain member email addresses and worse.

- `SmSecretRedactor` already strips secrets from job output. **A parallel pass for personal data is required** before any diagnostic bundle reaches a model.
- The sentinel model makes the model's location the customer's choice. Local inference is the default that matches the platform's stated direction; a customer may opt into a hosted model with informed consent, per node.
- Sealed content is never readable by the driver, and no rung may attempt to make it so. A restore returns sealed data intact and still sealed; it does not need to read it.

---

## 12. Out of scope, and stated in the contract

Named plainly, because a guarantee with unstated holes is worse than a narrow one:

- **The node runs Joinery and nothing else.** Rungs 6 and 7 wipe whatever else was installed alongside it. Enforce at provisioning; this is the largest scope hole and it is contractual, not technical.
- The customer's registrar and DNS.
- Provider outages and provider account problems.
- A move to a new machine where the IP changes and DNS is the customer's to update.
- The customer's own account hygiene: break-glass is the provider's rescue path from *their* account, so the guarantee's floor is that they keep their passwords and recovery codes (§13.11). Total loss of their accounts has one documented answer — abandon the box, restore to fresh ground — and its one hard dependency is their recovery key, which nobody else can substitute for.

---

## 13. Decisions — all twelve resolved with the owner, 2026-08-22

1. **Hosted sentinels and the subscription — RESOLVED 2026-08-22: neither ships in v1.** Nothing subscription-shaped is built now, and nothing that puts a solo developer on the hook for uptime or a response window. **v1 is the box, self-contained:** runbook entries arrive with ordinary platform releases (consistent with §13.12 — entries ride the channel that already exists), and escalation pages **the owner** through the existing alert paths, not getjoinery. The later phase — explicitly on the map, not designed here — may add the runbook feed, named human escalation, the getjoinery probe vantage (§13.6), and hosted sentinels; if hosted sentinels arrive they carry per-customer key isolation and live most naturally as the managed tier's internal tooling rather than a self-serve SKU, so the public lineup stays "sentinel box" and "managed by humans" with nothing custody-blurring between.
2. **Remediation consent — RESOLVED 2026-08-22: three plain-language tiers, not a per-rung matrix.** **Tier 1, "fix things" (rungs 1–2): on by default** — non-destructive, reversible, no data at stake; a sentinel that cannot restart a service is not a sentinel. **Tier 2, "restore from backup" (rungs 3–5): one opt-in checkbox** with one honest sentence — "If repairs fail, the sentinel may restore your site from backup; this can lose data back to the last good backup." Splitting 3/4/5 into separate consents is fake granularity — they are the same decision at different depths — and the §13.10 snapshot-skip question rides under this tier as its one sub-option. **Tier 3, "rebuild the machine" (rungs 6–7): not a checkbox at all** — it is inherently human-present conduct, already gated by the §4.3 key ceremony and the provider credential the customer may decline (§13.4); the enrollment screen states that plainly. Enrollment reads as three sentences; the dashboard shows active tiers per node; the incident record names the tier each action ran under.
3. **The model — RESOLVED 2026-08-22: bring your own, with a capability floor; default is none.** The ladder is fully functional with no model at all (§15 step 5 — the AI only ever improves rung choice), so the default configuration ships modelless and the guarantee is written against the deterministic ladder. A customer supplies their own model through `joinery_ai`'s existing provider configuration — a local box for full sovereignty, or a hosted key behind §11's per-node informed-consent screen. **The sentinel recipe declares a minimum capability NEED via the existing model-capability-resolution system**: a configured model below the floor is refused for the recipe — the ladder runs exactly as if no model were configured, and the dashboard says so in plain words ("AI assist inactive — model below capability floor") rather than letting a weak model make bad rung choices quietly. The sentinel's minimum machine spec is untouched by any of this: inference happens wherever the customer already has it, never on the sentinel.
4. **Rung 7 as a separate grant — RESOLVED 2026-08-22: yes.** The base product is rungs 1–6; the provider token is a separate, plainly-described grant carrying the account-wide warning (§6 — on most providers the token can destroy every instance in the account, so declining rung 7 is declining the largest-blast-radius ask for the rarest failure). Rung 6 already covers "the OS boots but the site is beyond repair"; without the token, machine-loss recovery is the owner's provider rescue path or abandon-the-box (§13.11), and the dashboard says so honestly: "machine-loss recovery: manual via your provider." Where the token is granted, rung 7 means **rebuild-in-place only** (same instance, same IP) — the existing provisioning path creates a *new* instance with a new IP, stranding the recovered site behind the DNS update §12 puts on the customer, so new-instance provisioning is always an escalation, never an automated rung. Consistent with §13.2's tier 3, rung 7 is human-present conduct regardless; this grant decides whether the credential is on file at all, not whether software fires it alone.
5. **Pricing shape — RESOLVED 2026-08-22 in shape; the number is deliberately open until launch.** The sentinel is a **one-time provisioned-install product** — the same commercial shape as the automatic install and the Linode one-click: the customer pays once for provisioning their second VPS in sentinel posture, and the VPS itself is their own provider bill. **No per-node fees, no metering, no recurring component in v1** — any of those would reintroduce the billing-and-obligation machinery §13.1 excluded. With the subscription deferred, backup frequency is just a setting, not a pricing dial; subscription pricing is designed in the later phase alongside §13.1.
6. **Second vantage and sentinel count — RESOLVED 2026-08-22: mutual node vantage is the default and the floor.** Every deployment with two guarded machines gets full-ladder capability out of the box, subscription or not — guarded nodes probe each other via the same node-side task the heartbeat needs, and the sentinel collects the observations. Sentinel count: allow N, require one; a second sentinel registers as just another vantage plus a guardian for the first — same machinery, no special architecture. The **getjoinery probe endpoint is a later phase** (subscription enhancement): a third, network-independent opinion and the tiebreaker for correlated failures where a customer's co-located nodes falsely confirm each other's death. Until it ships, the one-guarded-node-plus-sentinel customer either supplies any URL-probe they control or accepts a rung-2 ceiling — and the dashboard and sales copy say so in plain words rather than papering over it.
7. **The rung 6–7 key custody question of §4.3 — RESOLVED 2026-08-22: human ceremony now, `specs/managed_backup_recovery.md` as a committed dependency, escrow closed permanently.** Rungs 6–7 ship with the password-manager ceremony (honest, costs nothing). But §13.11 made the recovery key the single irreplaceable credential in the whole design — it also gates the abandon-the-box last resort — so this spec *depends on* managed_backup_recovery for its full guarantee rather than politely cross-referencing it: once built, the key is recoverable from any one surviving customer credential and the floor stops having a single point of failure. The dashboard's coverage panel treats "recovery key verified recoverable" as a first-class item (password-manager proof today, wrapped custody once built). **Options 3/4 — sentinel escrow or pre-seeding of the site key — are closed, not deferred**: they cannot serve the abandon-the-box path anyway (the sentinel may be gone too), so they would buy automation of a rare case at the price of the "sentinel holds no key that reads your backups" property in every case. This decision must not be reopened under schedule pressure.
8. **Licensing for the sentinel box — RESOLVED 2026-08-22: a sentinel consumes no second licence.** One sentence joins the existing staging/backup clause in `LICENSE-BUSINESS.md`'s grant — to the effect of: "A sentinel instance — an installation whose sole function is monitoring, repairing, and backing up a licensed production instance, and which serves no site of its own — does not count as a production instance" — pinned by a matching assertion in `manifest_licensing_test`, same as the existing pins. The exemption is tied to the sentinel *posture* (the §14.H bundle, no public site in use): a box that starts serving a real site is a production instance that happens to run Server Manager, and owes a licence. The licence edit and test pin land at build time with §15 step 6 (packaging).
9. **Rehearsal — RESOLVED 2026-08-22: the full clone rehearsal rig is NOT built.** A scratch-box clone of a customer site is a live site needing quarantine, a third machine, and consent about where member data lands — and the fraction of the audience willing to run a third box is negligible; those who are belong on the managed tier or aren't Joinery candidates. Instead, per-node proof is the **database restore check**: on a schedule, the node restores its own newest backup dump into a throwaway *database name* (never over the live one), asserts it loads and row counts are sane, and drops it. No clone, no cron race, no data leaving the machine. **Two hard conditions:** (a) a **disk-space preflight** — estimate required space from the dump size with headroom, and if the node lacks it, *skip and report "not proven — insufficient disk"* rather than run (a low-disk skip is itself a dashboard signal, and the check must never be the thing that fills a working box's disk); (b) **guaranteed cleanup** — the scratch database and temp files are dropped on every exit path including failure and interruption (trap-based), plus a sweep at each run start for orphans left by a killed prior run. Restore-*path* proof at full fidelity (files + reconcile + boot) is done at the **core level, periodically, on our own fleet** — not per customer node. The dashboard line becomes "last backup opened and loaded: N hours ago," which is true and still a real trust artifact.
10. **When the pre-destructive snapshot fails — RESOLVED 2026-08-22: consented skip, decided at enrollment, default off.** The owner answers one plain question up front: *"If your database is already unreachable when a repair needs to run, may the sentinel proceed using your last completed backup, accepting that anything since then is lost?"* Consent granted → the driver may skip the snapshot **only when** the snapshot failed **and** the diagnosis is one where it could not have captured anything anyway (postgres already down after rung 1's restart failed — the data at risk is already unreachable). In every branch where the snapshot *could* succeed, it still must. No consent → stall and escalate. The incident record states which branch ran and why. Two structural mitigations narrow the gap regardless: rung 2's disk-reclaim precedes every destructive rung, and postgres-down only reaches this gate after a restart already failed. The checkbox defaults **unchecked**, consistent with §13.2's posture that destructive autonomy is asked for, never assumed — and mid-incident consent is never solicited; the decision exists only at enrollment.
11. **What break-glass actually is — RESOLVED 2026-08-22: the provider's rescue boot, from the customer's own account; nothing is minted or stored.** A serial console alone is a window, not a door (no typeable credential exists on a node); the ceremony is rescue-boot → mount → chroot, and the enrollment checklist verifies the provider has rescue mode and the customer can reach their provider account (login + 2FA recovery codes). **The guarantee's floor is that the customer keeps their passwords and recovery codes.** When even that fails — provider account unrecoverable — the answer is not heroics against the old box: **the box is disposable and the backups are the asset.** getjoinery provisions fresh ground and restores from backup (rung 7 on new ground, human-driven, offered as a service). That path has exactly one hard dependency: the customer's **recovery key**, without which nobody — getjoinery included — can open the backups. The recovery key is therefore the floor's single irreplaceable member, which is the strongest argument for §13.7's managed_backup_recovery adoption. Carve-outs, stated in the contract: hardware with no rescue path (home/colo) makes break-glass physical access; full-disk-encrypted nodes are outside the break-glass path entirely.
12. **Runbooks: code or data — RESOLVED 2026-08-22: data, over a fixed vocabulary.** A runbook is a record (precondition, primitive + bounded params, verification, rollback) over an enumerated set of primitives; the feed ships records and is safe to auto-apply because a record cannot ask for anything outside the vocabulary. New *primitives* are code and arrive via the ordinary signed upgrade channel — subscription copy says "new entries continuously, new capabilities with platform updates," which is true and verifiable. This is what makes the sudo allowlist definable (§5) and keeps the feed from becoming an executable supply chain into customer fleets. **Diagnosis is menu-driven the same way**: named evidence collectors, not free-form reads — load-bearing for §11's redaction promise (§5, §14.E). Accepted consequence: early on, most new failure modes need new primitives, so the feed is thin until the vocabulary matures; the pitch must not imply otherwise.

---

## 14. Components

Sized by scope, not schedule. "Reuses" names what already exists; only the **New** column is work.

### A. Rung library — the runbook  ·  *largest, and never finishes*

The enumerated repairs, each carrying precondition, action, verification, rollback.

- **Reuses:** rungs 3–7 exist as builders (`build_restore_database`, `build_restore_chain`, `build_install_node`).
- **New:** rungs 1–2 as builders (restart php-fpm / postgres / container / agent; reclaim disk; renew certificate; finish a half-applied upgrade). Precondition and verification wrappers around every rung, including the existing ones — a builder today assumes an operator judged the situation; a rung must judge for itself.
- **The real cost is empirical, not code.** Each entry has to be proven against a deliberately broken box, and the library grows for as long as the product lives. This is the thing customers are actually buying, and the only component whose value compounds.

### B. Incident record  ·  *medium*

One object per event: detection and vantages, each attempt and its outcome, why the driver advanced, resolution, data window lost.

- **Reuses:** `mjb_management_jobs` already holds the attempts.
- **New:** the data class grouping them, plus **two** renderings — an operator view and an owner-facing plain-language timeline. The second is the sale (§10), and it is not the first with different CSS.

### C. Ladder driver  ·  *small–medium*

The scheduled task that turns a confirmed down-transition into dispatched repairs, holds the clock, and advances rungs.

- **Reuses:** `FleetBackupRun`'s exact shape — select due work, build steps, `ManagementJob::createJob()`, concurrency guard on `pending`/`running`. `RunNodeUptimeChecks` already produces the transitions.
- **New:** the wire between them, the per-incident clock, and rung advancement.

### D. Guard rails  ·  *medium, and subtly hard*

Multi-vantage confirmation; correlated-failure suppression; pave-rate cap (which also protects Let's Encrypt duplicate-certificate limits); the restore-check gate; escalation classes.

- **Reuses:** `NodeMonitorHealth`'s inconclusive/stale/misconfigured modelling — the hard-won half.
- **New:** everything that only matters once software decides instead of an operator. **This is where the bugs will live.** It is small in lines and large in care.

### E. Evidence bundle + redaction  ·  *medium — the substantial AI-adjacent work*

Gather the right diagnostic signal off a broken box, strip personal data, structure it for a model.

- **Reuses:** `SmSecretRedactor` as the pattern.
- **New:** a personal-data pass (today's redactor covers secrets only), the collection set per failure class, and a size discipline — a model reasons worse over an unfiltered log dump, so this is a quality problem before it is a privacy one.
- **Collectors are the diagnostic menu (§5, resolved at §13.12):** each is a named, parameterless-or-bounded read (service states, journal tail, disk/memory, error-log extract, DB connectivity, config syntax, cert status, upgrade state, …) with a known output shape the redactor is written against. The AI selects collectors the way it selects rungs; a collector it wanted and lacked is a library-gap signal, and collectors are the cheapest entries in the library to add.

### F. AI selection layer  ·  *small — genuinely*

Given the bundle and the permitted rungs, choose one, supply parameters, justify it.

- **Reuses:** `ActionRegistry` + `_logic_descriptor()` declarations, `DescribeActionsTool` against a recipe's `rcp_allowed_actions` allowlist, `InvokeActionTool` for validated invocation, `CostGuard` for spend ceilings, the existing recipe/executor supervision path.
- **New:** the rung descriptors, one recipe definition, and the prompt.
- **It stays small only if the menu discipline holds.** The driver executes; the model selects. No tool in the recipe's allowlist may accept a free-form command.
- Riding on this machinery means the sentinel bundle includes `joinery_ai` — "minimal" is `server_manager` + `joinery_ai` (§14.H).

### G. Database restore check  ·  *small, and it gates everything above rung 2*

On a schedule, on each guarded node: restore the newest backup dump into a throwaway database name, assert it loads and row counts are sane, drop it (§9, §13.9).

- **Reuses:** the chain-open machinery (`backup_site_key` on the node) and the dump artifacts already on disk or on the target.
- **New:** one small builder, the disk-space preflight (skip-and-report below headroom — no restore script has a free-space check today), trap-based cleanup on every exit path, the orphan sweep at run start, and the scheduler + result record.
- The full clone rehearsal rig (scratch box, quarantine, boot assertion) is **deliberately not built**; full-fidelity restore-path proof runs at the core level on our own fleet.

### H. Sentinel packaging  ·  *small–medium*

- **Reuses:** `install_bundles.json` is the existing install-profile hook — a `sentinel` bundle (`["server_manager", "joinery_ai"]`) is a one-line JSON entry consumed by the existing `install_bundle.php` step.
- **New:** the bundle entry, the agent-liveness self-heartbeat of §7.3 (published by the sentinel, plus the node-side relationship record and the small scheduled task on each guarded node that checks it), correlated-failure posture, and the provisioning profile the sale is made from.
- **Deliberately not built:** a headless "no public site" mode. No such mode exists anywhere in the platform and it would be new product surface for no operational gain — a sentinel that serves a stock login page is boring enough.

### I. Feed client + escalation sender  ·  *small — later phase (§13.1)*

Not built in v1 (runbook entries ship with releases; escalation pages the owner).

- **Reuses:** the `MarketplaceClient` signed-catalog fetch pattern and `SafeHttpClient`.
- **New:** the runbook-feed poll, the outbound escalation POST, and the getjoinery-side receiving endpoint. All traffic originates at the sentinel; it exposes nothing inbound (§8).

### J. Per-node support credentials  ·  *medium*

The §6 standing-key class, which does not exist in any form today (the fleet shares one provisioning key on disk).

- **Reuses:** SecretBox for at-rest custody; the agent's existing in-memory credential placeholder pattern (`__SM_CREDS__`) as the model for handing the agent a key that never touches disk.
- **New:** per-node key minting and deployment, the restricted support account and its sudo allowlist (the node-side enforcement of §5), rotation, the customer-facing revoke control of §6, and agent support for SecretBox-held SSH keys.

---

## 15. Order, by dependency

1. **B + C, rungs 1–2 only.** Teach `RunNodeUptimeChecks` to open an incident on a confirmed down-transition; add the driver task. No restores, no AI — a fixed rung 1→2 sequence. Proves detection, clock, escalation, and the record against the least dangerous repairs.
2. **G.** Independent of everything above, and a prerequisite for claiming anything about restores. Small since §13.9 descoped it to the database restore check — but the disk preflight and cleanup guarantees are not optional trim; they are the component.
3. **D.** Before any destructive rung is reachable.
4. **A rungs 3–5**, behind G's gate.
5. **E, then F.** The AI arrives *after* the ladder already works without it — so its failure mode is a worse rung choice, never an unrecovered site.
6. **J, then H.** The credential class before the packaging: nothing ships to a customer on the shared fleet key. (I is later-phase subscription work — §13.1.)
7. **A rungs 6–7** and the provider credential class, with §4.3 and §13.4 (rebuild-in-place) resolved.

**Steps 1 and 2 are worth building even if the product is never sold.** An incident record and proven restores improve the fleet that exists today, on a single box, with no sentinel and no subscription.
