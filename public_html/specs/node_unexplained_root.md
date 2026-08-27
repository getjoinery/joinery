# Unexplained Root — Asserting That Nothing Ran as Root

**Status:** **DRAFT — 2026-08-27, unbuilt.** Written in response to the owner's question: *"we're designing the environment so ssh is off and there are no root logins, but most of the remaining risk is someone getting root through a vulnerability — can we assert that no root activity may happen, and trip an alarm if it does?"*

**Answer, in one line:** yes, but only if the assertion is phrased against the approval ledger rather than against a local config, and only if the node is made to **prove its quiet continuously** instead of reporting its noise. Everything else in this spec follows from those two sentences.

**Where it sits:** this is the execution-side sibling of `specs/agent_on_node_architecture.md` §3.6. Attestation watches **files** — what is on disk, and what got planted there. This watches **execution** — what actually ran with uid 0. Persistence and action are different halves of the same compromise, and a real attacker has to touch at least one of them.

**Prerequisites:** the agent on every node (`agent_on_node_architecture.md` §6, Phase 1 shipped 2026-08-26) and its signed channel. This spec adds no new transport, no new key, and no new daemon of its own beyond `auditd`.

---

## 1. The trap this design exists to avoid

An alarm that runs on the node can be switched off by the thing it is watching. Root kills the reporter; the plane sees silence; **silence looks exactly like health.** Every naive version of this feature fails here, and fails in the worst possible direction — it reads as coverage while providing none, which is worse than having built nothing, because a fleet dashboard showing twelve calm nodes stops anyone from looking harder.

So the reporting is inverted:

> The node does not report when something happened. The node continuously proves that **nothing** happened — a signed, monotonically sequenced statement that as of sequence N there have been zero unexplained privileged events. A missing statement is an alarm. A gap in the sequence is an alarm. A counter that resets without a correlated boot is an alarm.

This is the platform's existing idiom, not a new one: §3.6 already rules that "silence is an alarm" for attestation, and the O-3 going-quiet goodbye already exists so that a *deliberate* silence is signed, acknowledged, and distinguishable from a suspicious one. This spec consumes both rather than reinventing them.

---

## 2. The assertion, stated precisely

"No root activity" is not checkable, because legitimate root activity is exactly what the agent does for a living. The checkable form is:

> **No process ran as uid 0 on this node except (a) an enumerated set of expected actors, or (b) inside an open, plane-recorded approval window.**

Both halves matter, and they fail differently.

### 2.1 The standing allowlist — expected actors

This is enumerable **because Joinery ships the box.** A managed node is not a general-purpose machine someone installs things on; it is a known software set installed by a known installer. The expected root actors are:

- the agent itself and the scripts its primitives invoke (already verified against the signed manifest before exec — §3.2, built);
- the init system and its unit startups;
- the distribution's own maintenance: `unattended-upgrades`, `logrotate`, `systemd-tmpfiles`, the cron jobs the node ships with;
- the platform's own cron (`run.php`), which is not root and whose becoming root is itself an alarm.

Anything else executing with uid 0 is **unexplained root**. That is the whole vocabulary: expected, windowed, or unexplained. There is no fourth bucket, and no "warn" tier — a warn tier is where alarms go to be ignored.

### 2.2 The windowed allowlist — the approval ledger

The node's own opinion is not enough, because a node's local config is exactly what a root attacker edits first. The second half of the assertion lives on the management node: the approval records for destructive jobs (§3.3's node-verified passkey signatures), the `ssh_window_open` windows of the A5 service posture, and the job history itself.

**The rule: an event is explained only when the node's compiled allowlist and the plane's ledger both say so.** Neither alone suffices.

This is the property worth the whole build. An attacker who owns the node can edit what the node believes is expected, but cannot mint an approval on the plane. An attacker who owns the plane can mint a ledger entry, but cannot make the node's compiled-in classifier call a strange binary expected — and per §3.5 a compromised plane is already assumed. **Making a root exec look routine requires compromising both sides at once**, which is a materially higher bar than either alone. It shrinks a §3.7 cell without shuffling trust between rows, which is that table's stated test for whether a new mechanism earns its complexity.

### 2.3 What "alarm" means

Not a log line. An unexplained root event:

1. **posts immediately**, out of band of the poll cadence — this is the one thing on the channel that does not wait for the next claim;
2. lands in **object-locked storage** (§3.5.7) before anything else happens, so it cannot be retracted by whoever is about to own the node;
3. opens an **incident record** (sentinel §10) and pages the owner through existing alert paths;
4. on a standalone node with no management node, surfaces in local admin and by direct mail — the same best-effort path §3.6 already specifies for attestation.

The ordering is deliberate: **ship the evidence off the box first, then raise the alarm.** The attacker's window to silence the reporter is then bounded by the flush, not by the alerting path.

---

## 3. Where the evidence comes from

### 3.1 auditd, with immutable rules

The Linux audit subsystem, with the rule set locked at boot (`-e 2`). Root can stop the daemon, but cannot quietly *unmodify* the rules — and the reboot required to change them is itself a reported, correlated event (§4.3).

The rules are deliberately narrow. Broad auditing is how this feature dies of volume, and it would also violate the read-side minimisation of §3.5 — audit records are content-bearing.

| Watched | Why |
|---|---|
| `execve` with `euid=0` | The core assertion. Every root command line. |
| `execve` where `uid != euid` | Privilege transitions — a setuid path being used is the escalation itself, not its aftermath. |
| `/etc/shadow`, `/etc/passwd`, `/etc/sudoers`, `/etc/sudoers.d/` | Credential edits. |
| every `authorized_keys`, `/root/.ssh/` | The classic persistence. |
| `/etc/systemd/system/`, `/etc/cron*`, user crontabs | The other classic persistence. |
| `init_module`, `finit_module`, `delete_module` | Kernel module load — the step that takes the attacker below this whole mechanism. |
| The agent's identity dir, its config, and the root-owned run marker (O-2) | The mechanism's own integrity. |

**Not watched:** general file reads, network activity, non-root exec. Volume kills the feature and buys little — a root attacker who only reads is discussed honestly in §7.

**Rejected for v1: eBPF (Falco, Tetragon).** Richer signal, and it is what a larger shop would use. It is also a second privileged daemon to keep alive, it is fragile across the kernel versions a mixed VPS fleet actually runs, and it can be unloaded by the same root that can stop auditd — so it buys resolution, not assurance. `auditd` ships with the distribution and is one `install.sh` line. Revisit when the fleet's kernel spread is narrower.

### 3.2 The classifier is compiled into the agent

The agent reads the audit stream (audispd plugin socket, falling back to a tail of the audit log), classifies each record against the compiled-in expected-actor set, and emits counts plus **full records for the unexplained ones only**.

Node-side and compiled-in, for two independent reasons — either alone would be sufficient:

1. **The read side.** Raw audit records carry command lines, which carry secrets and personal data. §3.5.2 puts the redaction boundary on the node, before anything leaves. The `SmSecretRedactor` pattern applies unchanged.
2. **The write side.** An allowlist the plane supplies is an instruction from the wire, which is precisely what decision A1 forbids and what the primitive vocabulary exists to prevent. The expected-actor set is compiled in, exactly like the primitive registry, and a plane cannot widen it.

This means the classifier lives in the agent's own package with the same posture as `primitives/` — a registry, a closed set, and a gate test asserting the set has no escape hatch in it.

### 3.3 The quiet proof rides the existing channel

No new transport and no new key. The agent already polls its management node outbound over HTTPS every 15 seconds (`pathClaim`), signing each request with the node-generated Ed25519 identity, and that poll already doubles as the liveness heartbeat. The quiet proof is three fields on that request:

- `root_seq` — monotonic, incremented every reporting interval, never reset except at a boot the node also reports;
- `root_unexplained_total` — cumulative since boot, expected to be zero forever;
- `boot_id` — the kernel's, so a reset is only ever legitimate across a boot the plane can see.

Plane-side, three conditions are alarms with no further interpretation: **a stale poll**, **a sequence gap**, and **a counter or `boot_id` reset with no correlated reboot record.**

The going-quiet goodbye (O-3) is the *only* legitimate way for a node to stop proving its quiet, and it is signed, acknowledged, and replayed if undelivered. That existing mechanism is what makes "silence is an alarm" affordable to actually enforce — without it, every deliberate maintenance stop would page someone, and within a month nobody would be reading the pages.

---

## 4. The three ways an attacker gets out of this, and what each costs them

Stating these is the point of the section; a defence whose evasions are undocumented is a ring, not a layer (§3.7).

### 4.1 Kill the reporter

Costs them the poll interval. The plane sees a stale node inside ~30 seconds and cannot tell "compromised" from "crashed" — which is the correct reading, and both are worth waking someone for. Whatever already flushed is already in object-locked storage and cannot be pulled back.

### 4.2 Steal the identity key and forge calm

The identity key is root-owned, so root gets it. It can then sign a continuing sequence of zeros indefinitely.

**This is real, and it is bounded:** forgery only helps *after* the attacker has already executed something as root, and that first exec is exactly what the reporter shipped off-box before they got there. The forgery hides the aftermath, not the arrival.

**Not fixed in v1, deliberately.** Sealing the key to a TPM would close it, and the fleet has no consistent TPM — recorded here as the one upgrade that would shrink this cell further, for whenever the hardware picture justifies it. Building a software-only "protection" for this would be the shuffling §3.7 forbids.

### 4.3 Reboot to disarm

Immutable rules (`-e 2`) mean changing them requires a reboot, so this is the cheapest disarm available. It costs the attacker a **loud, correlated event**: an unscheduled reboot with no approval record, arriving with a fresh `boot_id` and a counter reset the plane specifically checks for. An attacker forced to make a visible move to become invisible is the outcome this design is trying to buy.

---

## 5. Narrowing what can become root

Detection gets dramatically cheaper the fewer legitimate paths to uid 0 exist, because every removed path is one fewer thing the classifier must distinguish. Most of this is already decided elsewhere and is referenced, not re-decided:

- **No standing remote auth** — A5's sshd-off-by-default with self-closing approval-gated windows (the service posture's design; A11 keeps sshd on across the current fleet, so this applies to future installs).
- **No arbitrary execution in the vocabulary** — A1, built. There is no `exec` class to abuse.
- **No sudoers entry for the web user**, and no setuid helper bridging web to root. This is the escalation path that actually matters, because a web-tier compromise is the likeliest breach (§3.7).
- **`NoNewPrivileges=yes` and a trimmed `CapabilityBoundingSet` on the platform's units**, `nosuid` on mounts that carry no legitimate setuid binary.

One new item that is cheap and worth its own line:

- **A boot-time setuid inventory.** Enumerate every setuid/setgid binary at install and compare on every boot. A *new* setuid binary is close to a zero-false-positive alarm — nothing legitimate on a Joinery node grows one outside a package upgrade, which is itself an expected actor with a record. This is a few lines in an observe primitive and it catches an escalation *before* the exec that uses it.

---

## 6. Why this ships in observe-only first

The failure mode of every intrusion-detection system ever built is the false positive. An alarm that cries wolf gets muted, and a muted alarm on a dashboard is worse than an absent one, because it reads as coverage.

So v1 does not arm. It ships in **observe-only mode across the operator's own fleet**: collect, classify, and publish the unexplained set to a panel, with no paging. The expected-actor vocabulary grows from what real quiet nodes actually do until the unexplained rate on a quiet node is **zero — not low, zero.** Only then does the alarm arm, and the arming is a per-node fact the plane records.

**The vocabulary is compiled in, so widening it is a release, not a config edit.** That is a feature and it is the point: it puts a permanent, deliberate cost on the temptation to quiet a noisy alarm by widening the allowlist, which is how these systems rot. The settling period ends when the rate is zero, and if it will not reach zero the honest conclusion is that the assertion is wrong for that node, not that the threshold should move.

---

## 7. The honest limits

Written down rather than patched over, in the register of §3.7:

- **Root that reads memory and leaves is invisible here.** An attacker who gets root, lifts a vault key out of a running php-fpm, and exits without exec'ing anything unusual trips nothing in this spec. There is no file to hash and no strange command line to classify. This is not a reason to skip the build; it is the reason the detection budget goes to persistence and privilege transitions, and the reason the standing rule holds that **any key resident in node memory is forfeit the moment root happens** — which is already why the plane never supplies encryption keys (A4).
- **A kernel-level attacker owns the collection path.** Module-load auditing raises the cost of getting there; it does not survive arrival.
- **A compromised plane can suppress the display.** The containment is that it cannot suppress the *record* — object-lock means the evidence outlives the plane's opinion of it, and the owner's dashboard reads from the record.
- **The §3.7 root row is not eliminated.** Root on a node is still that node, entirely. What changes is the cell's content: from *"that node entirely, indefinitely and invisibly"* to **"that node entirely, with the fact of it undeniable and the dwell time bounded by the poll interval."** By the rule that governs that table, shrinking a cell is what earns the complexity — and this is the shrink being bought.

---

## 8. Open questions for the owner

- **R1. Fleet-wide or sentinel-only?** Recommend **fleet-wide**, on §3.6's reasoning: every Joinery install carries the agent, so every install can carry this, and a security property that only paying customers get is a worse story than one the platform simply has.
- **R2. Who arms, and on what evidence?** Proposed: arming is per-node, plane-recorded, and gated on a stated settling period with a zero unexplained rate. Needs an owner call on whether arming is an operator action or automatic on meeting the bar.
- **R3. Standalone nodes have no approval ledger.** With no management node there is no second opinion, so §2.2's both-must-agree property degrades to the node's own allowlist. Does a standalone node keep a **local** approval record for this purpose, or does it accept the weaker assertion and say so plainly in its admin?
- **R4. `auditd` becomes an install dependency.** Not present on a minimal Ubuntu image. It costs a package, some disk for its buffer, and a small steady CPU load. Confirm that is acceptable fleet-wide, and that the zero-config install rule tolerates it (it should — it is a package, not a required setting).
- **R5. Is the reporting separable from the agent running?** Proposed: **no.** If the agent runs, it proves quiet; there is no switch that leaves the agent working while the assertion sleeps, because that switch is the first thing an attacker would look for. Switching the agent off is the going-quiet goodbye and reads as such.
- **R6. Retention and lock window** for the root-event record in object-locked storage — aligned with backup retention, or longer? An incident is usually discovered well after it happened, which argues for longer.

---

## 9. Components

- **A. Agent: audit source + classifier** · *medium* — audispd socket reader with log-tail fallback, the compiled-in expected-actor registry (same posture and gate test as `primitives/`), redaction on the emit path, the setuid inventory.
- **B. Agent: the quiet proof** · *small* — `root_seq` / `root_unexplained_total` / `boot_id` on the existing claim request, the immediate out-of-band post for an unexplained event, persistence of the counter across restarts.
- **C. Node install: audit rules** · *small* — the §3.1 rule set, `-e 2`, package dependency in `install.sh`, and the units hardening of §5.
- **D. Plane: ledger correlation + alarm** · *medium* — stale/gap/reset detection, the both-must-agree check against approvals and job history, the object-locked write, incident record, alert paths, and the observe-only panel with the per-node arming state.
- **E. Standalone path** · *small* — local admin surface and direct-mail notify, reusing §3.6's standalone notify path once it exists.

**Build order:** A and C together (they are useless apart), then D in observe-only, then B's alarm arming last — which is also the order that lets the settling period start as early as possible, since the vocabulary cannot be grown from anything but real fleet data.

---

## 10. Relationship to the other specs

- `agent_on_node_architecture.md` — supplies the channel, the identity, the goodbye, the object-locked storage rule, and §3.7's table, which this spec is scored against. §3.6's attestation is the file-side half of the same coverage; the two should arm together and share the standalone notify path.
- `sentinel_managed_recovery.md` — supplies the incident record and the alert paths. An unexplained-root incident is **not** a ladder candidate: no rung repairs a compromise, and rung 7 on a compromised node restores the attacker's persistence along with everything else. It pages a human and stops, and Appendix A should carry that row explicitly.
