# Sentinel — Managed Recovery for Joinery Nodes

**Status:** **DRAFT 2026-08-22 — unbuilt.** Design only; no code written. The remediation machinery this spec assembles already exists (`JobCommandBuilder` builders, `reconcile_site.sh`, backup chains, `NodeMonitorHealth`, Joinery Direct). What is new is the **incident record**, the **ladder driver**, the **rehearsal scheduler**, and the **sentinel** packaging. Open decisions are collected in §13 and must be resolved by the owner before build.

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

**Almost none of this is a new system.** Server Manager already holds managed nodes with SecretBox-encrypted credentials, runs the agent and job queue, owns every rung of §4 as a builder, and runs scheduled tasks against the fleet. Two existing tasks bracket the entire gap:

- **`RunNodeUptimeChecks`** already probes each node on its interval, computes up→down and down→up **transitions**, alerts once per transition without re-alerting while down, and — from the false-down flood — already records a probe that died in the monitoring host's own resolver as **inconclusive rather than down**. It detects correctly. It then only sends an email. *It reports; it does not act.*
- **`FleetBackupRun`** already does the acting half for a different purpose: on a schedule it selects due nodes, calls `JobCommandBuilder::build_backup_run()`, and dispatches with `ManagementJob::createJob()`, under a concurrency guard counting `pending`/`running` jobs.

So the pattern *scheduled task → build steps → create job → agent executes* is built, shipped, and proven in production. **The sentinel is the wire between those two tasks**, plus the record that makes it legible and the guards that make it safe.

What is genuinely new:

| New | Existing it is built from |
|---|---|
| Ladder driver (consumes down-transitions, dispatches repairs) | `FleetBackupRun`'s dispatch shape, exactly |
| Incident record (detection → attempts → escalation → outcome) | `mjb_management_jobs` holds the attempts; the narrative grouping them does not exist |
| Rehearsal scheduler | `build_restore_chain` + the throwaway-domain parameter |
| Escalation + runbook feed | Joinery Direct kinds |
| Multi-vantage gate, rung caps, rehearsal gate, correlated-failure suppression | new, and only necessary once software decides rather than an operator |

**The one requirement beyond "a second VPS": the sentinel must be a *minimal* install.** Today Server Manager typically runs on a control plane that is also a real site. That is fine for an operator's remote control; it is wrong for a guardian, because a sentinel also running mail and a store shares a failure surface with the thing it is watching. A sentinel's whole value is being more boring than its charges.

**The trust posture changes even though the code does not.** Today a human clicks Restore and owns the consequence. Under this spec, software decides. That single difference is what every guard rail in §7 and §8 exists to earn — they are not padding, they are the price of removing the operator from the loop.

### 2.3 Therefore: the box is the product, the feed and the human are the subscription

This is the same open-core split already in use. Stated as a rule for build:

- **A sentinel with no subscription never degrades.** It keeps its runbooks, keeps watching, keeps repairing, keeps rehearsing. It does not nag, phone home, or hold function hostage. Anything else contradicts the platform's stated business model and would be read as a rug-pull by exactly the audience being sold to.
- **The subscription adds** runbook updates, second-vantage confirmation (§7.2), and named human escalation.

**Commercially this is a tier of a plugin already sold, not a new SKU.** The sale becomes "a second small VPS, provisioned, running a minimal Joinery with Server Manager in sentinel posture" — which fits the existing provisioned-install commerce rather than needing its own product line. One licensing decision falls out and is listed at §13.8: **a box whose only job is guarding another box should not require a second licence.**

### 2.4 The guarantee splits along the same seam

This is the sharpest consequence and it inverts the usual SLA shape:

- **The machine guarantees resolution.** Bounded ladder, measured rehearsals, published record. This requires no trust — it is verifiable by the owner from their own dashboard.
- **The service guarantees response.** A human answers within a stated window.

You cannot write an uptime SLA over infrastructure you do not control, and should not try. What you can do is publish measured facts and let the record be the guarantee. That is the site's existing **"verify, don't trust"** register (see `specs/getjoinery_site_redesign.md` §3), applied to operations.

---

## 3. The guarantee, stated precisely

> **Your site back and working, losing no more than X of data, within Y of confirmed detection.**

A **data-loss guarantee, not an uptime guarantee.** Restore returns a site to a point in time, not to now.

**X has two branches, and both are stated to the customer:**

| Situation | Data loss |
|---|---|
| Node reachable but broken (the common case) | **Near zero** — a fresh backup is taken *before* any destructive rung |
| Node hard-down / unreachable | Back to the **last scheduled run** |

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

Three ways to resolve this, and the choice is a real one (§13.7):

1. **Accept it.** Rungs 6–7 are break-glass by nature — a machine rebuild deserves a human anyway (§8 already escalates on rung 7). The owner is paged, taps their passkey, the sealed recovery key opens for a time-boxed window, and the restore proceeds. This is the *existing* break-glass shape and costs nothing new to build.
2. **Escrow the site key on the sentinel.** Makes rungs 6–7 fully unattended, but the sentinel then holds material that opens that node's archives — a genuine custody change that weakens the "the sentinel holds no key that reads your backups" property.
3. **Pre-seed the replacement.** Write the old site key onto the fresh machine before restoring. Same custody question as (2), since the sentinel must have held a copy to write it.

**Recommend (1).** It preserves the strongest property of this design — a sentinel can drive a full recovery without ever holding a key that decrypts a customer's backups — and it puts the human exactly where §2.4 already says the human belongs.

---

## 5. The AI drives, but it does not hold the wheel

**The AI never authors a command string.** It selects a rung and supplies parameters; the server builds the steps. This is already how `JobCommandBuilder` works and the driver must not add an escape hatch.

Each rung is a runbook-as-data — **precondition, action, verification, rollback** — matching the executor-supervision pattern already used elsewhere in the platform.

**The clock, not the cleverness, is the guarantee.** The driver holds a budget. While the budget lasts, the AI may diagnose, gather evidence, and choose among low rungs. When it expires, the ladder advances on its own. An AI that wants an action not in the library does not get it — that request is an **escalation trigger** (§8), and a signal that the library needs a new entry.

**Allowlist enforcement belongs on the node, not only on the sentinel.** Precedent: the agent already refuses to install a binary the publisher did not sign, on the reasoning that the web tree is writable by the web user while the agent runs as root. The same argument applies to remediation actions. A compromised sentinel must not be able to talk a node into arbitrary work.

---

## 6. Credentials

Three classes. Under the sentinel model, custody of all three sits with the customer.

| Class | What it is | Custody | Used by |
|---|---|---|---|
| **Standing support key** | Unique-per-node SSH key, restricted account, sudo allowlist | Sentinel, SecretBox-encrypted at rest | The driver, unattended, rungs 1–6 |
| **Break-glass root** | Unbounded access | **Sealed to the owner's passkey vault** | A human, in a time-boxed window |
| **Provider API token** | Power and rebuild actions | Sentinel, scoped as narrowly as the provider allows | Rung 7, and reboot-when-unreachable |

**Why not simply store the root password.** It is not revocable without the customer's help, cannot be scoped, is very often reused elsewhere, and the fleet already authenticates with keys. A minted, per-node, revocable key is strictly better on every axis.

**Why passkey-sealing cannot protect the working credential.** Sealed content is unreadable by unattended code — a hard platform invariant. A root password sealed behind the owner's passkey is unreadable at precisely the moment it is needed: node down, owner asleep. Passkey-sealing therefore protects **break-glass only**, where a human is present by definition. The unlock window shape already exists in the vault.

**Revocation is a customer-side control.** Every node's support key must be revocable from the owner's own admin, individually, without contacting anyone. This is the answer to "what if I stop trusting this," and it needs to be visible, not buried.

**Backup confidentiality is not a prerequisite — it is already closed.** Encryption is the default on every path, and the automated paths cannot produce a plaintext archive at all:

- `backup_project.sh` and `backup_files.sh` default to `ENCRYPT=true`. A run that cannot find a key **fails** rather than downgrading: *"Never silently downgrade to a plaintext archive: an automated run that cannot encrypt must fail, not quietly ship `config/` in the clear."*
- The chain path (`build_backup_run`) has no plaintext branch whatsoever — it always mints an envelope against the recovery public key.
- The two legacy ad-hoc builders can pass `--plaintext`, but only when an operator has explicitly unchecked encryption **and** the node has no cloud target, since a configured target force-sets `encryption = true`.

An unencrypted archive therefore requires a deliberate human act at a shell. Nothing in this spec's automated path can create one.

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

The second vantage is a natural subscription benefit: getjoinery offers a probe endpoint sentinels can ask for a second opinion. A customer without a subscription supplies their own second vantage or accepts a ceiling of rung 2 on single-vantage evidence.

### 7.3 Who watches the watcher

A sentinel is a box, and boxes go down. Three rules:

1. **A sentinel never guards itself.** The minimum viable deployment is two machines. Say this in the sales copy rather than discovering it during provisioning.
2. **A sentinel publishes a heartbeat** the guarded nodes can see, so a dead sentinel is visible from the thing it was supposed to protect — inverted from the usual direction, and the only arrangement that survives the sentinel dying quietly.
3. **A sentinel backs itself up** to the same targets it manages, and is itself rebuildable by the ladder. Its state is a Postgres database and a folder, like everything else.

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

**Transport:** escalation to getjoinery is a Joinery Direct **kind**, with the runbook feed as a second kind. This gets authentication, the declined/deferred semantics, and the spool for free, and keeps the sentinel from needing any inbound exposure.

---

## 9. Rehearsal is the investment

**An untested restore is a hope.** Current fleet state makes this concrete: retention deletion has never been live-tested, five nodes have no backup target, and the recovery key panel has an explicit `unproven` state. The platform already thinks in these terms — this spec makes it a scheduled obligation.

**The rehearsal loop:** on a schedule, restore each guarded node's newest chain into a throwaway target, assert the site boots and row counts match expectation, record the result, destroy the target.

The backup system already anticipates this. The domain is a required restore parameter precisely because "a rebuild keeps the site's own domain and a rehearsal must not claim it" — rehearsal is a designed-for case, not a hack.

**The rehearsal result is the guarantee.** A node whose last rehearsal failed is **not covered**, and its dashboard says so in those words. This flips the guarantee from a promise into a measured fact, and "your last verified restore was 6 hours ago" is a better trust artifact than any SLA paragraph.

---

## 10. What the customer sees

One **incident record** per event, readable without technical knowledge, containing: what was detected and from which vantages, what was tried, what each attempt did, why the driver moved to the next rung, what finally worked, and what data window was lost if any.

Standing dashboard state, always visible: last verified restore per node, current data-loss window, monitoring health, which credentials are held and a revoke control for each.

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
- Anything requiring break-glass root — that path requires the owner by design.

---

## 13. Open decisions

1. **Does getjoinery run sentinels for customers who want no box of their own?** This re-creates the shared-plane blast radius for that cohort. Recommend: yes, but as a separate, explicitly-described product with per-customer key isolation — not as the default.
2. **Is remediation opt-in per rung, or blanket-with-exclusions?** Blanket sells better; opt-in is what a security-conscious buyer will demand. Recommend: blanket through rung 4, explicit opt-in for 5–7.
3. **Local or hosted model by default**, and what the sentinel's minimum specification becomes if local inference is standard.
4. **Rung 7 without a provider credential** — is an in-place reinstall (rung 6) enough to sell the guarantee, making provider access a premium add-on? Recommend: yes.
5. **Pricing shape** — sentinel box price, subscription price, whether the data-loss window (backup frequency) is the primary dial.
6. **How many sentinels may one customer run**, and whether sentinels may cross-check each other as second vantages instead of relying on getjoinery.
7. **The rung 6–7 key custody question of §4.3** — accept the human ceremony, or escrow the site key for full automation. This is the single largest determinant of how the guarantee reads for a total-machine-loss event.
8. **Licensing for the sentinel box itself** — a guard box should almost certainly not consume a second licence, but the bundled-suite scoping needs to say so explicitly.

---

## 14. Build order

1. **Incident record + ladder driver, rungs 1–2 only.** No restores. Proves detection, the clock, escalation, and the customer-visible record against the least dangerous repairs. Concretely: teach `RunNodeUptimeChecks` to open an incident on a confirmed down-transition, and add a driver task that dispatches from it the way `FleetBackupRun` already dispatches backups.
2. **Rehearsal scheduler.** Independent of everything else, immediately useful to the existing fleet, and a prerequisite for claiming anything about restores.
3. **Rungs 3–5** behind the rehearsal gate — a node with no passing rehearsal cannot reach them.
4. **Sentinel packaging** — provisioning profile, self-heartbeat, correlated-failure suppression.
5. **Joinery Direct kinds** — runbook feed, escalation.
6. **Rungs 6–7** and the provider credential class.

Steps 1 and 2 are worth doing regardless of whether the product is ever sold; both improve the fleet as it stands today.
