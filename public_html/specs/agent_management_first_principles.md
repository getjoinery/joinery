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
| Managed node | Its own Ed25519 identity (0600 root), its own proven recovery **public** key (which is also what approvals are sealed to), root-owned policy, and its own upload ledger of what it sent to the bucket | A database credential (dies with the local queue); any fleet-shared secret; the recovery **private** key, which only a human has |
| Publisher (dev box) | The release signing key (0600, offsite in its own sealed backup) | — custody is the only defense; a compromised publisher is stated game-over |
| Operator (human) | Personal per-node SSH keys (internal/BYO only), the backup recovery private key that answers a destructive approval | — |
| A provisioned customer machine | Nothing of ours, ever — no key is placed at creation (`keyless_provisioning.md`) | Any SSH key of ours, at any point, for any duration |

**Operations:** every steady-state maintenance operation is a primitive.
The current vocabulary (15 primitives) already covers status, backup
run/list/upload/delete, apply-update, plugin installers, certificates and
SSL probes, agent restart, recovery-key report, and the three restores.
What deliberately never becomes a primitive: provisioning-time operations
(`install_node`, `enable_agent`, `discover_nodes` — they run before pairing;
for machines we create the install moves to a first-boot script and none of
them is used at all), `decommission_node` (a provider API call on the
customer's grant, not a script on a dying box), `publish_upgrade`
(publisher-local), and the relay operations (dead code at cutover).

**The standing acceptance test** (owner, 2026-08-28, restated as the bar for
"the agent is capable"): *a maintenance task that requires a shell on a
production install is a vocabulary gap to close, never a runbook step.*
That test — not a date, not a version — is what "when the agent is capable,
remove the keys" means.

## The programme

In dependency order. **This table is the only place any of these carries a
status**; the spec named is where its design lives.

| # | Item | Spec | State |
|---|---|---|---|
| 1 | Destructive approval on the node | `implemented/restore_dispatch_approval_mechanism.md` | **BUILT + DEPLOYED 2026-08-30.** Fleet 9/9 on agent 1.13.0; gate open on every paired node |
| 2a | Prove one restore end to end | `restore_readiness.md` (acceptance) | **NEXT, and small.** Precondition arrives on its own with the next scheduled backup |
| 2b | Restore readiness — make the gap visible | `restore_readiness.md` | Follows 2a; does not gate it |
| 2c | Delete the three SSH restore builders | this table | **UNBLOCKED 2026-08-30** by the live restore. They were kept only until one had been done over the channel; they are already unreachable, refusing through `refuse_dead_restore_transport()`. Homeless otherwise: their spec is in `implemented/` and must not be edited |
| 2d | Deferred destructive approval | `deferred_destructive_approval.md` | The approval window is bounded by how long a node can afford to be deaf, not by what a person needs. Raised to 60m as an interim |
| 3 | The plane-side executor (WP1+WP2) | `plane_side_executor.md` | **BLOCKING.** Twelve operations have no transport since SSH was removed |
| 4 | Keyless provisioning | `keyless_provisioning.md` | Needs only executor WP1, not the whole executor |
| 5 | Credential custody — delete the shared key | `fleet_ssh_credential_custody.md` | WP1 done; WP3–WP5 gated on item 2 |
| 6 | The last raw-SSH flows | `plane_side_executor.md` WP5 | `ProvisionManagedDomains` / `ManagedDomainWatch`; gates jeremytunnell's key |
| 7 | Retire the local queue | `agent_local_queue_retirement.md` | Last, not first — thirteen operations still depend on it |
| 8 | Per-node hardening | — | A per-node ceremony, not a fleet event. `environment_build_surface_reduction.md` rides alongside |

**Why item 7 is last, which is the reversal this family keeps re-deriving.** The
local queue is the largest hole in the platform — a web-tier database write is
management-node root — so it looks like it should be first. The 2026-08-30 audit
found thirteen operations still riding it: seven are deletions, four move to the
executor item 3 builds, and two are gates of their own. Flipping the flag before
those land does not close a hole; it breaks the fleet and gets flipped back.

**Item 2a is next, and it is one operation.** Everything downstream of the
restore round assumes a restore works, and nobody has run one. Item 5 must not
proceed before it: deleting the shared SSH key removes the fallback, and doing
that while the replacement has never been exercised is the trade this programme
exists to avoid making by accident.

**2a and 2b are separated deliberately, after review.** It is tempting to say
readiness unblocks item 5. It does not — the live restore does, and its only
precondition is one ledgered backup, which the next scheduled run provides. What
readiness answers is the permanent case: a fresh node, a rotated recovery key, a
ledger whose permissions were broken. Those are nodes that believe they are
protected and are not, and no scheduled run heals them.

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

**Not met:**

- A backup that exists only in the bucket is brought back to its own node and
  restored, end to end, on a real node. *(item 2)*
- Every operation in the executor's table dispatches and completes. *(item 3)*
- A provisioning password authenticates an install without ever being written to
  a machine. *(item 4)*
- `config/provisioning_key` does not exist, and no node's `mgn_ssh_key_path`
  names a fleet-shared key. *(item 5)*
- The executor runs as the site user and no step it runs is privileged. *(item 3)*
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
| `fleet_ssh_credential_custody.md` | The full `config/provisioning_key` consumer inventory, and jeremytunnell's particulars |
| `agent_local_queue_retirement.md` | The thirteen-operation audit, with the two gates and seven deletions |
| `restore_readiness.md` | The gap item 2 closes |
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
