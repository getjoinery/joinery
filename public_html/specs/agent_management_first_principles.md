# Agent Management — the programme, from first principles

**Status: DIRECTION — for owner review. Consolidated 2026-08-30 and now the
SINGLE SOURCE for this family's work map and acceptance criteria.** This is the
destination the agent and security work is driving toward **without major
rewrites** — every mechanism named below is already built, already specified, or
is a flag. It settles the five questions, records the owner decisions that
settle them, and owns the ordered programme. It invents nothing new.

> **HOW THIS FAMILY IS ORGANISED, after the consolidation.** Five specs had
> overlapping work lists and repeated the same acceptance criteria up to three
> times each — "inserting a row into `mjb_management_jobs` does nothing" appeared
> in three, the local queue's retirement was both its own spec and another's WP6.
> That is how a programme drifts: two documents disagree about what is done and
> nobody can tell which is stale.
>
> So: **the ordered work map and the acceptance criteria live here and nowhere
> else.** The other specs keep only what is uniquely theirs — a design, an audit,
> a consumer inventory, a provenance record — and point here for status. They are
> annexes, listed at the bottom. `agent_management_first_principles_INPUTS.md` is
> folded in as Appendix A and deleted; `r5_cutover_inventory.md` is a superseded
> snapshot.

## Where we're going, in plain terms

A management node runs your fleet without holding anything that opens your
fleet. Every managed machine runs a small root agent that phones home,
outbound only, and will execute exactly the operations compiled into it —
named operations with fixed parameters, scripts checked against a signed
release manifest — and nothing else. No machine-held SSH keys, no shared
credentials, no command strings crossing the wire. Anything destructive
(a restore, a decommission) additionally requires a human's approval that
the *node itself* issues and verifies, answered with the backup recovery
key on that node's own site, so even a fully compromised management node
cannot destroy anything. Customers' machines carry zero
credentials of ours and have no back door; our own machines keep sshd for a
human with a personal key, as a safety net — not as a management channel.
**Every piece of this is built as if those keys did not exist: nothing in
the job system, the deploy scripts or the tests may read one. The keys
already on our own sites stay where they are, for a human troubleshooting
by hand (owner, 2026-09-05).**

That is the whole design. Everything below is that paragraph made precise.

## The five questions, settled

### 1. What is the agent for?

**One role: a node's own manager.** It gathers status and executes named
primitives dispatched by its management node over the signed channel. It
never self-initiates (A10 — the diagnose-and-decide intelligence lives on the
management node), and it is never a generic executor.

The other two roles it holds today are scaffolding and they die:

- **The legacy local-queue executor** (unsigned `bash -c` from
  `mjb_management_jobs`) — dies via `agent_local_queue_retirement.md`.
- **The legacy SSH transport runner** (`ssh`/`scp` step types executed on the
  management node's own agent) — dies in the same removal.

The `__SM_CREDS_` claim-time credential resolution stays: it is part of the
channel (secrets ride the signed hand-out, never rest in the job row).

### 2. Where does authority live?

**In the management node's dispatch, bounded twice on the node.** The signed
channel's model wins outright; the database-as-authority model loses and its
machinery is deleted. A node accepts a job only when:

1. it is a **known primitive** with parameters that pass the node's own
   `Validate` (the wire can never name a script path, a version source, or
   an arbitrary argument), and
2. the node's **root-owned policy** accepts that class — with the
   destructive class additionally requiring an approval the node issued
   itself and verifies itself (question 4's mechanism). Built: the node
   seals a job-bound one-time challenge to its own proven backup recovery
   public key and stages it for its own admin page; the answer never passes
   through the management node in either direction.

The database is a queue, never an authority. When the local queue is gone,
inserting a row into `mjb_management_jobs` by hand does nothing.

### 3. What is the trust boundary between the web application and the plane?

Honestly: **there isn't one, and building one is the major rewrite this spec
declines.** The web stack and the dispatch logic share a process and a
database user. What changes is what that is *worth* to an attacker:

- Today: web-stack compromise = root on the management node (the local
  queue), and from there the fleet.
- End state: web-stack compromise = the ability to dispatch **observe and
  operate primitives** to paired nodes. Not root anywhere, not destructive
  anywhere, not a shell anywhere. Refusals are counted per node
  (`mjb_agent_outcome`), so a probing attacker is a visible spike, not a
  log-grep.

This residual is an accepted limit (see the table below), and it is why the
missing web-application audit matters: the initial-compromise likelihood is
the one thing none of this work reduces.

### 4. What is disaster recovery, per node kind?

| Node kind | Recovery path | If the agent is dead |
|---|---|---|
| Provisioned customer node | Restore-over-agent (built 2026-08-30; needs agent 1.13.0 on the node) | Provider rescue **through the customer's own cloud account** — their machine, their consent. No platform back door exists to fall back on, by design. |
| Internal / BYO managed node | Restore-over-agent, same mechanism, same recovery-key gate (A2: no privileged home fleet) | Personal SSH with a per-node key — the deliberate safety net (A11), used by a human, never by the job system. |
| Disposable (2 DNS resolvers, relay) | Reprovision from scratch | Same — they are rebuilt, not recovered. |

What makes restore-over-agent trustworthy is already doctrine: backups seal
only to the node's own proven recovery key (A4 — the plane can neither read
nor re-seal them), object-lock retention means plane credentials cannot
delete them inside the window, and the agent's supervisor (@reboot + cron
keepalive + signed self-update with watchdog rollback) is critical path —
tested and monitored, never trimmed — because on a customer node it is the
only pair of hands we have.

### 5. Which nodes live without an agent?

The disposable trio, permanently (A8, reaffirmed 2026-08-30 — recorded in
`feedback_dns_relay_disposable_never_agented` memory and in the retirement
spec). Nothing else. Every future managed node is a Joinery install and
pairs at birth (`install.sh --enable-agent`, fingerprint-compared approval).

## The end state, concretely

**Credentials, by holder:**

| Holder | Holds | Never holds |
|---|---|---|
| Management node | Node public keys, per-node write-only backup slots, its own site | Any SSH private key the job system can use; any node's recovery key; anything that opens a customer machine |
| Managed node | Its own Ed25519 identity (0600 root), its own proven recovery **public** key (which is also what approvals are sealed to), root-owned policy, and its own upload ledger of what it sent to the bucket | A database credential (dies with the local queue; one transient exception: a host-posture agent staging a decommission approval holds the victim's DB credential in memory for the life of the connection — never at rest, never its own; `docker_host_agent.md`); any fleet-shared secret; the recovery **private** key, which only a human has |
| Publisher (dev box) | The release signing key (0600, offsite in its own sealed backup) | — custody is the only defense; a compromised publisher is stated game-over |
| Operator (human) | SSH keys to our own machines (internal/BYO only) — the ones already there stay, unchanged; the backup recovery private key that answers a destructive approval | — |
| A provisioned customer machine | Nothing of ours, ever — no key is placed at creation (`keyless_provisioning.md`) | Any SSH key of ours, at any point, for any duration |

**Operations:** every steady-state maintenance operation is a primitive.
The current vocabulary (15 primitives) already covers status, backup
run/list/upload/delete, apply-update, plugin installers, certificates and
SSL probes, agent restart, recovery-key report, and the three restores.
What deliberately never becomes a primitive: provisioning-time operations
(`install_node` runs before pairing and is the one SSH session a machine
ever gets from the plane; `enable_agent` and `discover_nodes` are deleted —
the machine's owner enrolls it from its own page; `ssh_single_bootstrap.md`), `decommission_node` for a whole machine (**manual, permanently**: the
operator deletes the machine at the provider by hand and then removes the
record from the dashboard; the platform never calls a provider's delete —
owner 2026-09-05 — but ONE Joinery site among several on a shared machine
is removed by the HOST's agent, which is not dying: `decommission_site`, per
`docker_host_agent.md`, owner 2026-08-31),
`publish_upgrade` (publisher-local), and the relay operations (dead code at
cutover).

**The standing acceptance test** (owner, 2026-08-28, restated as the bar for
"the agent is capable"): *a maintenance task that requires a shell on a
production install is a vocabulary gap to close, never a runbook step.*
That test — not a date, not a version — is what "when the agent is capable,
remove the keys" means. **Removing the keys means the job system stops
needing them, not that they leave the machines: existing keys on current
sites stay for hand troubleshooting (owner, 2026-09-05).**

## The programme

In dependency order. **This table is the only place any of these carries a
status**; the spec named is where its design lives.

| # | Item | Spec | State |
|---|---|---|---|
| 1 | Destructive approval on the node | `implemented/restore_dispatch_approval_mechanism.md` | **BUILT + DEPLOYED 2026-08-30.** Fleet 9/9 on agent 1.13.0; gate open on every paired node |
| 2a | Prove one restore end to end | this table | **DONE 2026-08-30** on joinerydemo: backup to the bucket, `download_backup`, `restore_database`, approved on the node's own site with its recovery key, site serving afterwards |
| 2c | Delete the three SSH restore builders | this table | **UNBLOCKED 2026-08-30** by the live restore. They were kept only until one had been done over the channel; they are already unreachable, refusing through `refuse_dead_restore_transport()`. Homeless otherwise: their spec is in `implemented/` and must not be edited |
| 2d | Deferred destructive approval | `deferred_destructive_approval.md` | The approval window is bounded by how long a node can afford to be deaf, not by what a person needs. Raised to 60m as an interim |
| 3 | The plane-side executor | `plane_side_executor.md` (design only; not endorsed as-is) | **ROLLED BACK 2026-08-31 and not rebuilt.** Round 1 (WP1+WP2 on three operations) was built, reviewed and deleted in full, spec included: it put the health check back on SSH from the plane, one layer above the agent whose whole design is health check plus predetermined fix. What survives is the minimal install-only `InstallJobExecutor` that item 4 needed (an `ssh`-over-sealed-password runner for `install_node`, routed by the `queued` status, zero agent change). The twelve operations that lost their transport are a list of things to disposition, not an executor's to-do list, and item 6 dispositioned them: `check_status` is a native primitive; the disposable trio gets health check plus reprovision and no agent; `provision_ssl` and fleet seeding crossed to the agent; `enable_agent` and `discover_nodes` are deleted. What is left of "executor" is item 7's two gates, `publish_upgrade` and Docker-host certificate issuance |
| 4 | Keyless provisioning | `keyless_provisioning.md` | **BUILT 2026-09-03, live gate open.** Provision over a sealed install password, host agent on every docker machine, the install password retired once every agent on the machine is admitted (the executor completes the retire job only after the machine refused the password), join approval checked with the provider. Owed: one live run per shape to `retired` |
| 5 | Credential custody — the platform holds no SSH key | `implemented/fleet_ssh_credential_custody.md` | **DONE 2026-09-05.** WP1 done; WP2 superseded by item 4. **WP3 CLOSED 2026-09-05 with nothing to build:** the container case is done (`decommission_site` on the host agent); a whole machine is deleted at its provider BY HAND and its record removed from the dashboard afterwards, and that stays manual — the platform never deletes a cloud machine programmatically (owner). "Permanently delete" on the dashboard remains the way to remove one Joinery site from a shared machine. The dashboard already says so on a dedicated machine's Overview tab. **WP4 DONE 2026-09-05 — the whole of item 5, all of it on the management node, no managed node changes:** the platform forgot `config/provisioning_key`: no PHP reads it any more (measured 2026-09-05); what remains is the pin in `fix_permissions.sh`, the entry in `installer_contract_test.php`, and the key pair sitting in `config/` where the web user can read it. The pair moved to the operator's `~/.ssh/joinery_provisioning_key` as a troubleshooting key (`fix_permissions.sh` 3.2 dropped the pin; installer contract test and plugin doc no longer name it) — it was verified by hand to still reach jeremytunnell, nothing on jeremytunnell changed, and jeremytunnell's `mgn_ssh_key_path` was repointed to the new file so the interim reachability check does not go blind. Tree-wide, only specs still name `config/provisioning_key`. Readers of `mgn_ssh_key_path` (`has_ssh()`, the node form, `PollHostingOrders`, the relay copy) die with item 7's `ssh` step type. **WP5 (rekey jeremytunnell) WITHDRAWN** by owner 2026-09-05: no key is removed from or replaced on any current site. The annex still lists WP5; this table wins |
| 6 | SSH is one bootstrap, run once | `implemented/ssh_single_bootstrap.md` | **DONE 2026-09-02**, live-verified on every shape: one install job = local preflight + one ssh session; certificates and fleet seeding over the agent; `enable_agent` and `discover_nodes` deleted |
| 7 | Retire the local queue | `agent_local_queue_retirement.md` | Last, not first — thirteen operations still depend on it |
| 8 | Per-node hardening | `environment_build_surface_reduction.md` | The image and install surface work only. **It removes no SSH key from any current site** — the existing keys are the human troubleshooting door and stay (owner, 2026-09-05). The move of getjoinery to its own box is no longer part of this item; it was motivated by SSH removal |

**Why item 7 is last, which is the reversal this family keeps re-deriving.** The
local queue is the largest hole in the platform — a web-tier database write is
management-node root — so it looks like it should be first. The 2026-08-30 audit
found thirteen operations still riding it: seven are deletions, four were the
pre-pairing installs that item 6 collapsed into one bootstrap session, and two
are gates of their own. Flipping the flag before
those land does not close a hole; it breaks the fleet and gets flipped back.

**Item 2a gated item 5.** Taking the shared key away from the job system
removes its fallback, and doing that while the replacement had never been
exercised is the trade this programme exists to avoid making by accident. The
live restore has been run, so item 5 is open. The key itself remains a human's
troubleshooting door; only the platform stops holding it.

**Restore readiness (a "can this node be restored" report) was cancelled
2026-09-04, not deferred.** Each case it would have reported is covered
elsewhere: a fresh node is ledgered by its first scheduled backup; a rotated
recovery key shows on every status check through `recovery_key_report`; the
ledger directory is pinned to 700/600 and excluded from the permissions sweep
(`fix_permissions.sh` 3.1). Do not re-derive it as a gap.

## What we are deliberately not building

Recorded so nobody re-derives these as gaps:

- **No web/plane process or database-user separation** — the major rewrite.
  The vocabulary bound plus the destructive gate is the chosen containment.
- **No container split, no Apache swap** — declined in the surface spec.
- **No node autonomy** — the agent implements, the management node decides
  (A10). Self-hosting the plane is the sovereignty answer.
- **No skeleton key, no escrow, no break-glass on customer nodes** — a
  plane that can open customer machines is the target we refuse to become.
- **No agent on the disposable trio.** Settled; stop re-opening.
- **No programmatic deletion of a whole cloud machine, ever** (owner,
  2026-09-05). `CloudComputeProvider::deleteInstance()` exists for the
  provision pipeline to clean up a machine it created and failed to finish,
  and nothing else. Retiring a machine is a hand operation at the provider.
  Removing one Joinery site from a shared machine is a different thing and
  stays on the dashboard (`decommission_site`, via the host's agent).
- **No removal or replacement of SSH keys on current sites** (owner,
  2026-09-05). They are used for troubleshooting by hand and stay. The work
  is built as if they did not exist — no job, script or test may depend on
  one — which is a different thing from taking them away. Do not re-derive
  a per-node rekey or key-removal ceremony as a gap.
- **No "the served page verifies itself"** — a check living in the attacked
  domain is a ring, not a layer (§3.7). Future mechanisms must shrink a
  cell of the limits table, never shuffle trust between rows.

## Accepted limits (the honest table)

| Compromise of | Yields | Bounded by |
|---|---|---|
| Management node web stack | Observe/operate dispatch fleet-wide; that site's own data | Vocabulary + destructive gate; refusal-spike counting |
| A managed node's web tier | That site's unsealed data | Sealed-at-rest doctrine; signed-tree attestation detects tampering on a minutes clock |
| A managed node's root | That node, fully | Blast radius ends at the node: no fleet credential lives there |
| The release signing key | Everything | Custody only |
| A customer's device | That customer | — |

## The missing input

Everything above bounds *blast radius*. Nobody has audited the web
application for the initial bug — uploads, archive extraction, path and
query construction, the API surface. That audit is the standing input to
prioritising all of this, and it is still unassigned.

## Acceptance — the whole programme

The single list. Each criterion appears here once and is not restated in the
annexes.

**Met:**

- A destructive job is refused by the node unless a human on that node's own
  site answers a challenge the node issued and verifies itself.
- The management node holds nothing that can approve, relay, or substitute that
  answer — enforced by wire format: no primitive declares a parameter that could
  carry one.
- Every paired node runs an agent that reports its own vocabulary, and routing
  is decided from that report rather than from a version guess.
- A backup that exists only in the bucket is brought back to its own node and
  restored, end to end, on a real node. *(item 2a, joinerydemo 2026-08-30)*
- No platform code, deploy script or test reads an SSH private key, and
  `config/` on the management node holds none. Keys on current sites are
  untouched. *(item 5, 2026-09-05)*

**Not met:**

- No operation other than `install_node` opens an SSH session from the plane,
  and `install_node` opens exactly one. *(items 3 and 6)*
- A provisioning password authenticates an install without ever being written to
  a machine. *(item 4)*
- `mgn_ssh_key_path` has no reader. *(item 7; the item 5 half — no platform
  code, deploy script or test reads an SSH private key, `config/` holds
  none, keys on current sites untouched — was met 2026-09-05)*
- The install runner runs as the site user and no step it runs is privileged
  on the management node. *(item 3)*
- The agent's `local` step type is gone, it holds no database credential, and
  inserting a row into `mjb_management_jobs` by hand executes nothing. *(item 7)*

## One reversal inside an implemented spec

`implemented/restore_dispatch_approval_mechanism.md` records the **pre-restore
safety dump** as a defect found while building and as a met acceptance
criterion. **That feature was removed on 2026-08-30**, the day after it shipped,
by owner decision: a restore happens because the current state is wrong, so
dumping it first preserved exactly what was being discarded and kept a full copy
of the database, per restore, indefinitely.

**That spec has been edited to say so** — a deliberate, owner-authorised
exception to the rule that implemented specs are never touched, because the
alternative was a document describing a safety mechanism the code does not have.
The correction is scoped: superseding notes at the four places the dump is
named, with the original finding and reasoning left intact, since the defect it
recorded was real and only the answer changed. What is knowingly given up: a
load that fails part way leaves the schema replaced with nothing to put back
(`RESTORE_LOAD_FAILED`); the answer is the archive itself, still on the shelf.

**The rule held everywhere else, and the exception is worth a decision of its
own.** This is the second time in two days that a completed spec needed
amending — the other being WP5, rehomed to item 2c above rather than recorded
where it belonged. A rule with no amendment path produces either stale documents
or ad-hoc exceptions, and both have now happened.

## Annexes

Each keeps its own design, audit or inventory. None carries a status or an
acceptance list of its own.

| Spec | What is uniquely its own |
|---|---|
| `plane_side_executor.md` | What the executor is, what moves onto it and what does not, and the job-lifecycle design |
| `keyless_provisioning.md` | The enrolment design, and coverage of every install path |
| `ssh_single_bootstrap.md` | The disposition of every remaining SSH reach: the one bootstrap session, and what goes to the agent or is deleted |
| `fleet_ssh_credential_custody.md` | The full `config/provisioning_key` consumer inventory, and jeremytunnell's particulars |
| `agent_local_queue_retirement.md` | The thirteen-operation audit, with the two gates and seven deletions |
| `environment_build_surface_reduction.md` | Image and installer surface; independent of all of the above |
| `r5_cutover_inventory.md` | **Superseded snapshot.** Kept for its measurements, not its open questions |

## Appendix A — the measured ground

Folded in from `agent_management_first_principles_INPUTS.md` (deleted; it was
never committed, so nothing is lost from history). Measured 2026-08-30 against
the running system.

**Why the prose in this codebase cannot be trusted, which is the reason that
document existed.** Every wrong conclusion in the session that produced this
family came from reading a code comment or a spec paragraph and believing it.
Comments describe what was true when written. The rule that came out of it:
**measure against the running system, then write.** Three of the four defects a
later security review found in the restore round were the same shape — a
property asserted in a comment and enforced nowhere.

**Facts, as measured. Several have since changed, and that is the point of
dating them:**

- Twelve live node rows: nine paired, three unpaired (both ScrollDaddy DNS
  resolvers and the relay — disposable by A8, permanently agentless).
- jeremytunnell.com alone uses `config/provisioning_key`, and is
  `mgn_ssh_user = user1` because its bare-metal install disables root login.
- 15 operations had `build_<op>_primitive`; 13 did not.
- `node_can_dispatch_destructive()` returned false unconditionally, so the three
  restores were built but gated shut and restores travelled by SSH.
  **Changed the same day:** the gate is open for a paired node on agent 1.13.0,
  the whole fleet is there, and the SSH restore builders are unreachable.
- The management node's agent is a root shell for its own database: it claims
  any pending row whose commands blob has no `primitive` key and executes a
  `local` step as root. Still true. Item 7 closes it.
