# Restore readiness — knowing a node can be restored before someone needs it

**Status: SPEC, unbuilt. Written 2026-08-30, the day the restore round was
deployed fleet-wide. Reviewed the same day (design + security); findings
folded in.** Programme item 2 in `agent_management_first_principles.md`. Small:
one observe primitive, one plane surface, no new trust and no new credential.

## The problem, in plain terms

A node refuses to restore from an archive it has no record of uploading. That is
the defence that stops a compromised management node serving arbitrary bytes
under an approved name, and it works: the record — the upload ledger — is
written on the machine that made the backup, at the moment it went to the
bucket, before the bytes were anywhere a management node could reach.

A ledger only knows about uploads made *since it existed*. So a node has a
period in which it holds months of backups and can restore from none of them,
and **nothing anywhere says so**. The dashboard shows a healthy node with a long
backup history. A person reading that screen concludes it is recoverable. They
find out otherwise at the only moment it matters.

That is true of the entire fleet today, verified rather than assumed: every
ledger was created hours ago by the 0.8.354 upgrade, zero backup runs have
happened since, and no node can currently be restored. **That particular
instance self-heals** — the next scheduled run ledgers each node, and chains
become restorable from their next full. What does not self-heal is the class:

- a **newly provisioned node**, between its first boot and its first backup;
- a node whose **recovery key was rotated** and not re-proven — its archives are
  fine and it cannot approve anything;
- a node whose **ledger permissions were broken** by a deploy or a stray chmod,
  which is the case that looks like nothing at all.

Each of those is a node that believes it is protected and is not. That is the
permanent problem; today's empty fleet is just the loudest instance of it.

## Why this, before the executor

The plane-side executor is larger and twelve operations are broken without it.
This still goes first, but **not for the reason first written here.** The
original argument was that readiness unblocks `fleet_ssh_credential_custody`
WP4 — deleting the shared SSH key, the only other way onto a node. It does not.
**The live restore proof unblocks WP4**, and that proof's only precondition is
one ledgered backup, which arrives on its own with the next scheduled run.

The durable case is the three permanent classes above. A safety property nobody
can see is a safety property nobody can rely on, and the whole restore round is
a safety property. That is worth a week of the executor's slip; it is not worth
delaying the live restore, which is why the live restore is an acceptance
criterion here rather than a thing this spec waits behind.

## What gets built

### 1. The node answers — a `restore_readiness` observe primitive

`ClassObserve`. **No parameters at all**, reporting both profiles, mirroring
`list_backups`: a zero-parameter observe is the established shape and there is
nothing here worth validating.

It reads only what the node already has, and reports:

- **Whether the ledger exists and is trustworthy** — present, and not group- or
  other-writable.
- **How many artifacts are ledgered, and the newest recorded upload time.**
- **Which run its chain is restorable from.** A chain re-uploads its
  `manifest.json` every run but not its older artifacts, so it becomes
  restorable from its next *full* onward — a real date the node can compute and
  nobody else can.
- **Whether it could approve a restore at all.**

Three constraints on how, each of which is the difference between this being
useful and this being another thing that can be wrong:

**It executes the approval preflight; it does not re-derive it.** "Could
approve" must be the answer the approval path itself would give — the same
function `Execute` calls, reading the same settings. Re-deriving "proven" in new
Go, or shelling the recovery-key script a second time, builds exactly the drift
`recovery_key_report`'s own header warns about: two pieces of code answering one
question, disagreeing later, and the disagreement surfacing during a restore.

**An untrustworthy ledger is a REPORTED REASON, never an error.** `readLedger`
returns `untrustedLedgerError` for a group- or other-writable ledger — which
means the naive implementation *fails the job* on the exact condition this
primitive exists to report, and the operator sees a broken observe rather than
"your ledger is writable and your restores will refuse". The primitive catches
it and reports it as a distinct reason. This is the shape three of the four
findings in the last review took: a property stated in prose and enforced
nowhere.

**Counts, dates, names and reasons — never hashes, never `object_key`.** The
ledger's entries are hashes of this machine's own archives and the bucket keys
they went to. There is no reason to ship either, so neither is shipped.

### 2. The plane shows it, and subtracts offers it knows are dead

- The node's Backups tab states readiness plainly: *restorable*, or *not
  restorable, and why*, with the remedy when the remedy is "take a backup".
- A node that cannot be restored is visible **from the fleet list**, not only
  from its own detail page. An operator does not go looking for this.
- Every readiness display carries **when it was measured**. If the answer folds
  into `mgn_last_status_data` it inherits that field's carry-forward semantics,
  and *carried is not measured*: a stale answer shown as current is the failure
  this spec is about, reproduced one layer up.
- **Stale is never rendered as not-ready.** The agent claims one job at a time
  and a restore awaiting approval holds its claim for up to fifteen minutes, so
  a readiness check dispatched during one simply queues. "No fresh answer" and
  "not ready" are different sentences.
- **Refresh on the event that changes the answer**: after every completed
  `backup_run`, auto-dispatch a readiness observe — the pattern
  `JobResultProcessor` already uses for `recovery_key_report`
  (`JobResultProcessor.php:479-489`), with the same
  `ManagementJob::activeOrRecentForNode()` dedupe. **The plane must not infer
  readiness from a backup result.** A successful upload looks like it implies a
  ledger entry; the plane concluding that is precisely the creep this spec
  forbids. Let the node re-answer.

### 3. The remedy is one operation, and it should be obvious

Every not-ready-because-empty node becomes ready by taking one backup under the
current release. The surface should say that in those words, and the backup it
names should be dispatchable from where the warning appears.

## The boundary: this reports, it never decides

The node's refusal at the moment of use, against its own ledger, remains the
only authority. The plane's opinion can only ever **subtract** an offer: a
wrongly-READY node still hits the node's refusal, and a wrongly-NOT-READY node
has only lost a button.

That asymmetry is what makes the design safe, and it is also where it can rot,
in the harmful direction:

- **A suppressed button must never be a dead end.** Stale not-ready, mid-
  incident, no way to attempt, is the cache being *relied upon*. Where the
  restore button is withheld, its place is taken by **re-check readiness now**,
  which dispatches the observe and refreshes. There is always a path forward.
- **Readiness is never read by the dispatch path.** Not by
  `JobCommandBuilder`, not by `node_can_dispatch_destructive()`, not by
  `has_primitive()`. The veto lives in the view layer or it does not exist.
  Pinned by a test, the way `restore_dispatch_test.php` pins the wire format —
  a principle nothing can fail is a principle already half-broken.

## What NOT-READY discloses

Naming it rather than hiding it, because concealment here would buy nothing.

The readiness surface tells anyone who can read it **which nodes are currently
unrecoverable**, which is exactly the set worth attacking. Accepted, for a
reason that survives being pushed on: the same set is already derivable from
data the same reader has. Ledger creation is the upgrade the plane itself
performed; counts and newest-upload follow from `bkh_backup_history` since that
date; chain-restorable-from is the first full after it, which `bkh` also shows.
Readiness is a convenience, not a capability.

And the attacker it would guide needs power the dashboard does not grant. The
management node cannot destroy a node — that is the approval gate's whole
point — and an attacker with database write is root-equivalent through the local
queue regardless, until `agent_local_queue_retirement.md` lands. Nothing here
moves either line.

The real mitigation is shrinking the window, and scheduled backups do that
without being asked.

## Acceptance

- A fresh node with no backups reports **not restorable**, says the ledger is
  empty, and names taking a backup as the remedy.
- The same node, after one backup run, reports **restorable** and names the
  artifact — and the check that says so was dispatched by the completion of that
  backup, not by a person remembering to ask.
- A node whose ledger is group-writable reports **not restorable for that
  reason**, as a readiness answer and **not as a failed job** — distinct from
  the empty case, with which it shares nothing but the verdict.
- A node whose recovery key is not proven reports that it could not approve a
  restore even though its archives are fine, and that answer comes from the
  approval preflight itself.
- The fleet list distinguishes a node that cannot be restored from one that is
  merely unhealthy, and shows how old the answer is.
- **The veto lives only in the view layer:** a restore dispatched to a node the
  plane believes not-ready is still built, still dispatched, and refused **by
  the node**. Asserted by a test, alongside one that fails if the dispatch path
  ever reads readiness.
- **The proof that the whole round works, which nothing yet demonstrates:** one
  node, one backup taken under this release, brought back from the bucket with
  `download_backup`, restored with `restore_database`, approved on that node's
  own site with the recovery key. Until that has been done once, every other
  criterion here is a report about a mechanism nobody has run. It gates
  `fleet_ssh_credential_custody` WP4, and it does not wait for the rest of this
  spec.

## Related

- `implemented/restore_dispatch_approval_mechanism.md` — the round this
  completes the operator-facing half of
- `agent_management_first_principles.md` — programme item 2
- `fleet_ssh_credential_custody.md` — WP4/WP5 are gated on the live restore
  above, not on the rest of this spec
- `docs/backups.md` — the upload ledger, and what a node refuses
