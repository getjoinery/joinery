# Restore Dispatch — Node-Verified Approval Mechanism

**Status: PLACEHOLDER / TO-DO — NOT BUILT, NOT SCHEDULED.** This is the deferred
half of the restore-over-agent work. The node-side restore primitives are built
separately (`specs/restore_over_agent_primitives.md`); this spec is the machinery
that makes a destructive primitive actually *dispatchable*, and nothing here is
written yet.

**Why it is deferred (owner, 2026-08-28):** building the restore primitives is
self-contained and low-risk; building the thing that authorizes a destructive
job is the single most delicate security mechanism in the whole architecture —
it is the foundation the sentinel work sits on — and it earns its own focused
round rather than being rushed alongside primitive-building. Do not fold it back
in without an explicit owner decision to schedule it.

## What this unblocks

Two nodes cannot be hardened (SSH fallback removed) until restore runs through
the agent, because a hardened node the plane cannot restore is a node you have
locked yourself out of recovering:

- **jeremytunnell.com** — the live mail host. Its launch-ready to-do names
  restore-over-agent as a prerequisite.
- **getjoinery.com** — moves to its own box first, then hardens.

The restore primitives exist after the other round, but they are **refused at a
compiled ceiling** (`primitives/policy.go` `Accepts(ClassDestructive)` refuses
unconditionally; the plane's `has_primitive()` gate keeps destructive ops on the
SSH transport). This mechanism is what drops that ceiling — the one and only
thing that turns a built-but-refused restore into a runnable one.

## The design (from the architecture spec, §13.O10 / §3 destructive path)

Recorded as owner-final in `specs/implemented/agent_on_node_architecture.md` and
in project memory; restated here so this spec stands alone.

**The plane is a relay, never a gate.** A destructive job is authorized by a
signature the *node* verifies itself, over a challenge the *node* issued. A
compromised plane cannot forge it, and captured unlock credentials replayed
through the plane open nothing.

The shape:

1. **At pairing (or a later enrolment step), the approving human's passkey
   public key is stored root-owned on the node** — alongside the node identity,
   0600 root, never web-tier-readable. Whose key it is is a node-side fact the
   plane cannot alter after the fact.
2. **The node issues a job-binding challenge.** When a destructive job is
   claimed, the agent produces a nonce bound to that specific job (job id +
   primitive + a hash of the resolved config), so an approval cannot be lifted
   from one job onto another.
3. **The human signs the challenge with their passkey**, in front of the node's
   own request — the plane carries the signature but is not trusted to have
   produced or checked it.
4. **The agent verifies the signature against its root-owned stored public key**
   before `Accepts(ClassDestructive)` is allowed to return nil. No verifier, no
   acceptance — which is exactly today's state.
5. **Uniform fleet-wide (A2):** the operator approves their own fleet's
   destructive jobs the same way. No privileged home fleet, no unattended
   destructive anywhere.

## What lands when this is built

- Agent: an approval verifier (Ed25519/WebAuthn-assertion verification against
  the stored key), the node-issued challenge, and the seam in
  `Policy.Accepts(ClassDestructive)` that consults it. The compiled ceiling
  drops from "always refuse" to "refuse unless this job carries a valid
  node-verified approval".
- Plane: the challenge round-trip (fetch challenge from node → collect the
  human's passkey assertion in the admin UI → hand it back on the job), and the
  `has_primitive()` destructive gate flips so restore routes to the primitive
  transport for a node that advertises approval capability.
- The three `build_restore_*_ssh` paths retire once the primitive path is proven
  live on a node, per the migration's remove-SSH-per-primitive rule.
- Then, and only then, jeremytunnell / getjoinery can drop their SSH fallback.

## Chain-restore staging — owed by this round, not the primitives round

The primitives round builds `restore_chain` to **refuse when its artifacts and
key are not already staged** at the node-side path — it names this section as
where the staging is added. Two things must land here for restore_chain to run
end to end:

1. **Envelope-open + artifact-download on the node.** The candidate shape is a
   single node-side `restore_chain_job.sh` that opens the chain envelope
   (`backup_envelope.php open` against the node's own recovery key), downloads
   what `manifest.json` names, and then calls `restore_chain.sh` — one program,
   one signed-manifest entry, one primitive, and the plane composes no heredoc.
   The primitives round leaves `restore_chain` wrapping `restore_chain.sh`
   directly with pre-staged inputs; swapping its wrapped script to
   `restore_chain_job.sh` is the small change here.
2. **A bucket-read credential question.** Artifact download reads from the backup
   bucket, but the node holds only a *write-only* slot (`bkt_node_credentials`,
   the credential the migration deliberately keeps off nodes). Resolve this the
   way cloud-delete was resolved for `delete_backup` — decide whether the plane
   hands the node a scoped, short-lived read credential at dispatch, or whether
   the download stays plane-side and only the local restore is a primitive. Do
   not ship a standing delete-or-read-capable bucket credential to a node.

## Cloud-only backups — restore needs a download, owed here

For all three restore primitives, a backup that lives only in the bucket (not on
the node's local disk) cannot be restored over the primitive path: the contract
carries no bucket, key, or credential by design, so nothing downloads the object
to `/backups` first the way the SSH path does. This is the exact mirror image of
`upload_backup`, and the same family as the chain-staging credential question
above — it wants either a download primitive (the twin of `upload_backup`) or a
plane-side download before the restore primitive runs, decided together with the
bucket-read-credential question. It is **not** a parameter — do not close it by
letting the plane hand the node a path or a credential.

## restore_database envelope fidelity — closed in the primitives round

For the record: `restore_database.sh` gains an additive envelope-sidecar
fallback in the primitives round (mirroring `restore_project.sh` 1.3.0) so the
primitive can restore envelope-sealed archives, not only legacy-key ones. This is
noted here only because it is the kind of fidelity gap that is invisible until
dispatch — it is already handled, nothing owed.

## Also gated on this same mechanism

`decommission_node` is destructive and has no primitive; it rides this same
approval flow when it is built. Do not build a second approval path for it.

## The seam that is already in place

Nothing here is speculative wiring — the round that builds the restore
primitives leaves a single, named place for each side to plug in:

- Agent: `Policy.Accepts(ClassDestructive)` is the one function that refuses; it
  is where the verifier consultation goes.
- Plane: the destructive routing gate inside `has_primitive()` is the one place
  that keeps restore on SSH; it is where `node_can_dispatch_destructive()`
  becomes true.

Both are documented as such in the primitives round so this work is a plug-in,
not an excavation.
