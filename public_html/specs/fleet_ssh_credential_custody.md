# Fleet SSH Credential Custody — provisioned nodes hold no platform credential

**Status: WP2 REMOVED — superseded by `keyless_provisioning.md` (owner,
2026-08-30). WP1 BUILT 2026-08-30, pending the agent publish; WP4/WP5 are
unblocked once the fleet is on 1.13.0.**

**THE RULE, which this spec previously got wrong: we never put a key on a
machine we create.** Not shared, not per-node, not throwaway. A design that
installs a credential and removes it later is rejected however short the
interval. Nodes that already hold a key are dealt with **manually** — there is
no automated retirement path, deliberately, because building one is how the
removal machinery gets built.

An earlier draft of this spec argued for a throwaway keypair "because every
existing path already speaks keys … what kind of secret it is, is an
implementation detail." That was wrong: a key is installed on the machine and a
password is not, and the difference is exactly whether something is left behind
to take back. It was built, produced two hundred lines of retirement machinery,
and was reverted. See `keyless_provisioning.md`.

Two earlier drafts before that proposed replacing the shared provisioning key
with a better key; both were wrong for the same underlying reason — the right
number of long-lived platform credentials on a customer's machine is zero, and
the right number of short-lived ones is also zero.

Separate from `environment_build_surface_reduction.md` (what the image
contains) and from the job-queue problem recorded at the end (which this spec
does not fix and which outranks it).

> **Status and ordering live in `agent_management_first_principles.md`.**
> This spec is an annex: it carries the design and nothing else. If this
> document and the programme disagree about what is done, the programme is
> right.

## The defect being fixed

`ProvisionCustomerCloud` installs **one shared SSH public key on every instance
it creates**. `run():69` reads a single key path from a global setting for the
whole batch; `handle_ready():113-125` passes its public half as the sole entry
in `authorized_keys` at instance creation. There is no branch on `cvp_origin`,
no branch on buyer — a paying customer's server gets the same key as an
internal one. `root_pass` is random and never stored, so that key is the only
way in.

The consequences are the ordinary ones for a shared credential: no per-customer
revocation, no rotation story (`ensureSshKey()` mints once and keeps forever),
and a single private half whose leak — a disk image, a backup, a copied volume —
opens every server the platform has ever built.

One node exists today (jeremytunnell.com, `cvp` row 107, `cvp_origin = admin`,
`cvp_install_mode = from_backup` from node 32). No customer has been through the
flow. This is a defect in the path, caught before it carried traffic.

## The model

**A provisioned node carries no long-lived platform credential.**

1. **Provision** with a first-boot script delivered through the provider API.
   No `authorized_keys`, no credential of ours on the machine, ever.
2. **Install** the site and the agent from that script, on the machine itself.
3. **Join** — the agent mints its own keypair and asks to enrol; the plane
   approves. Nothing is discarded because nothing was placed.
4. **Manage** over the agent channel from then on. Forever.

Steps 1-3 are specified in `keyless_provisioning.md`.

**Break-glass on a customer node: none.** It is their machine, billed to their
cloud account. They have whatever access they want to it; our reach ends where
their consent does. Disaster recovery is the agent, and restore-from-backup
behind it.

**Withdrawn.** This paragraph argued for a throwaway keypair over the root
password on the grounds that "what kind of secret it is, is an implementation
detail." It is not: a key gets installed on the machine, which is what creates
the obligation to remove it. Neither is used — nothing is placed on the machine
at all. See `keyless_provisioning.md`.

### Scope: which nodes this applies to

| Node kind | Credential |
|---|---|
| Provisioned through a customer cloud grant | **None.** This spec. |
| Internal nodes, DNS boxes, the relay, BYO customer hardware | An SSH key, because there is no grant behind them. Per-node, never shared. |

## Why this is deliverable — the enforcement already exists

**The transport layer enforces the model for free.** With `mgn_ssh_key_path`
empty, `has_ssh()` is false, and `JobCommandBuilder` cannot build an SSH
transport for that node at all. An operation with no primitive simply does not
dispatch. No policy is needed to keep SSH from creeping back in; the absence of
the credential *is* the policy.

**The flows a customer node actually needs are already agent-only.**
`build_provision_certificate()` refuses outright without a paired agent — it
throws "cannot issue its own certificate: no agent has paired with this plane" —
and `ProvisionPendingSsl`, the unattended flow that keeps a customer's
certificate valid, uses only `provision_certificate`, `ssl_probe_place` and
`ssl_probe_clear`. All three are primitives. The single most important
long-running unattended operation on a customer node already works this way.

**What falls away rather than needing replacement.** `build_provision_ssl` (the
SSH variant behind a node-detail button), `backup_database` and
`backup_project` are manual admin fallbacks superseded by primitives or by this
decision. `install_node`, `enable_agent` and `discover_nodes` run *before*
pairing. Under `keyless_provisioning.md` `install_node` stops being an SSH-driven
remote install for machines we create; `enable_agent` and `discover_nodes` apply
only to machines we did not create, which supply their own credential. The relay operations and
`publish_upgrade` never target customer nodes.

## WP1 — Open the destructive gate  ✅ BUILT AND DEPLOYED 2026-08-30

Programme item 1. The confirmation is the node's own **backup recovery key**,
not a passkey — a passkey belongs to an account on a site, and that site would
be the management node, so a passkey gate is the plane authorising its own
destructive job. **Anywhere this spec says *node-verified passkey*, it means
that.** Design and provenance: `implemented/restore_dispatch_approval_mechanism.md`.

**WP3–WP5 below are gated on programme item 2**, one proven live restore. They
delete the fallback; doing that before the replacement has ever been exercised
is the trade this spec exists to avoid making by accident.

## WP2 — MOVED

Keyless provisioning is now its own spec: **`keyless_provisioning.md`**. It
covers every install path, what blocks each one, and the enrolment design that
keeps decision A6 intact.

Nothing about it belongs here any more. This spec keeps only the work that is
about *custody of credentials that already exist*: the destructive gate (WP1),
decommission through the provider (WP3), deleting the shared key and its
consumer inventory (WP4), and jeremytunnell (WP5).

## WP3 — Decommission through the provider, not the box

`decommission_node` has no primitive and does not need one. Deleting a
customer's VPS is a `deleteInstance()` call on the grant already held
(`CloudComputeProvider`), not a script run on a machine that is about to cease
to exist.

## WP4 — Delete `config/provisioning_key`

No shared key, no per-node replacement for provisioned nodes, no graduation
step. The file and its setting go. Full consumer inventory:

- `plugins/server_manager/activate.php:16` — calls `ensureSshKey()` on **every
  plugin activation**, so the key is recreated until this is edited.
- `logic/admin_provisioning_setup_logic.php:45,53` — the setup page generates
  the key and writes the setting. Needs removing from the setup flow, not just
  deleting.
- `ProvisioningSetup.php` — `ensureSshKey()`, `defaultSshKeyPath()`, and the
  `:489` status read.
- `ProvisionCustomerCloud::run():70` — see WP2.
- `JobResultProcessor:1193` — a provisioned node hosting a relay shard copies
  its key path into `mrl_ssh_key_path`.
- Tests pinning it: `provisioning_setup_test.php` (the `ensureSshKey` suite),
  `tests/unit/installer_contract_test.php:858`,
  `fleet_auto_enrollment_test.php:150`.
- `fix_permissions.sh` — remove the pin. Coordinate the header version bump with
  `environment_build_surface_reduction.md` WP1, which also edits that file.

## WP5 — jeremytunnell.com

A consequence, not a goal. It is the one node built by the old path, and it is
internal, so it lands in the second row of the scope table: give it its own key
rather than the shared one.

Its `mgn_ssh_user` is `user1`, not `root` — the bare-metal installer pre-stages
`user1` with NOPASSWD sudo and then disables root SSH login — so the file to
edit is `/home/user1/.ssh/authorized_keys`. Install the operator key, **verify
reachability over it**, then remove the provisioning key, then repoint
`mgn_ssh_key_path`.

No operational cost: those jobs are executed by the root agent, which reads a
600 `user1`-owned key without difficulty, exactly as it does for the other
eleven nodes.

## The agent's supervisor is now load-bearing

`install_agent.sh` registers `@reboot root` and a per-minute root cron
supervisor (`:351-352`), and the agent takes signed self-updates. Today those
read as resilience. Under agent-only management they are the entire reason the
model is safe on a machine we have deliberately given up access to. They should
be treated as critical path — tested, monitored, and never trimmed for tidiness.

## Open questions

1. **The job queue confused deputy — bigger than this spec, and untouched by
   it.** The management node's agent runs as root, loops on `ClaimNextJob()`
   (`main.go:406`), takes any pending row from `mjb_management_jobs` whose
   commands blob has no `primitive` key (`db.go:189`), and executes a `local`
   step as `exec.CommandContext(ctx, "bash", "-c", resolved)` (`runner.go:207`,
   `:291`). **There is no signature, HMAC or manifest check anywhere in
   `runner.go`.** The web application writes that table as its normal dispatch
   path, so web-stack database access is management-node root — and from there,
   the whole fleet, by primitive rather than by SSH.

   It is not an oversight: the design assumes the database *is* the plane and
   its writer is the operator. That held while the only writer was an admin
   screen; it stopped holding when the same database user began serving the
   whole public application.

   The remedy is the migration already under way, finished — the local agent
   consumes primitives instead of command strings, and the `local` and `ssh`
   step types leave the runner. `ClaimNextJob` filtering *for* non-primitive
   jobs is the marker of how much is left. **Needs its own spec, and probably
   outranks everything here.**

2. **Does anything still need the `local` step type on the management node?**
   Answering this scopes open question 1.

3. **Relay operations** emit SSH steps against a host that is disposable and not
   SSH-reachable. A correctness question, not a custody one, but confirm
   deliberately dead rather than quietly broken.

## Provenance

Three drafts on 2026-08-30. The first proposed sealed per-run keys and was
reviewed NEEDS-REWORK — its mechanism did not fit (the private key's consumer is
the root agent reading a path from the database at step-execution time, not an
in-process caller), its threat model missed the job queue, and its lifetime
model was wrong. The second proposed per-node keys and a graduation step; the
graduation step contradicted the intent, and per-node keys were still one
credential too many for a machine we do not need to reach. The owner's model —
throwaway credential, agent-only management, no break-glass on customer nodes —
is this document.

Findings F1-F11 from that review are folded in throughout; the fleet facts it
confirmed are unchanged, and its `ssh.go` / `runner.go` / `main.go` tracing was
independently re-verified before this rewrite.
