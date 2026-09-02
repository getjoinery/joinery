# The plane-side executor

**Status: REQUIRED AND BLOCKING as of 2026-08-30.** The agent's SSH transport
was removed that afternoon, so **twelve** operations now fail loudly with
nowhere to run. This spec is what they move to.

> **RESTORE IS NO LONGER AMONG THEM (2026-08-30).**
> `restore_dispatch_approval_mechanism.md` shipped: `download_backup` and
> `stage_chain` bring an archive back over the channel, and
> `node_can_dispatch_destructive()` is now true for a paired node running agent
> 1.13.0 or later, so the three restores route to primitives instead of falling
> through to a dead SSH builder. **WP4a is closed by its second route, not its
> first** — the SSH restore builders were not put on the executor and should not
> be. They remain in the tree unreachable, refusing with
> `refuse_dead_restore_transport()`, until a live restore has been done over the
> channel. The count above is now twelve operations, not twelve plus three.
>
> The fleet is at agent 1.10.0, so this is unreachable until the 1.13.0 agent is
> published and applied. That is a publish step, not a code one.

Measured against `mgn_managed_nodes` the same day — twelve live rows. **Nine
paired** (3, 29, 30, 31, 33, 34, 35, 176, 10107), all at agent 1.10.0, none
reporting a primitive vocabulary; of those, eight are container nodes and one
is bare metal (jeremytunnell, 176). **Three unpaired** — the disposable trio
(DNS Primary 27, DNS Secondary 28, Relay 1800), all bare metal and all
siteless. Every operation that routes to a primitive still works. Everything
that fell back to SSH does not.

> **Status and ordering live in `agent_management_first_principles.md`.**
> This spec is an annex: it carries the design and nothing else. If this
> document and the programme disagree about what is done, the programme is
> right.

## What it is, in plain terms

Today the management node runs a root process that reads job rows out of the
site database and does whatever those rows say — including opening SSH
connections to other machines and running command strings there. That process
is the Go agent, and the arrangement means **anything able to write the site
database can execute commands as root on the management node, and from there
reach the fleet.**

The executor is the same work moved into the management node's own PHP
application: it takes the steps of a job, runs them, and records what happened.
The difference is that it runs as the ordinary site user, takes its work
in-process rather than re-reading it from a table, and holds credentials for the
duration of a job rather than forever.

## Why now

Two independent needs converged, and a third made it urgent.

**It is the only thing that closes the confused deputy.** The agent's `local`
step is `exec.CommandContext(ctx, "bash", "-c", resolved)` with no signature,
HMAC or manifest check anywhere in `runner.go`. That is the largest hole in the
platform (`agent_local_queue_retirement.md`).

**It is the only place a provisioning password can live.** Keyless provisioning
authenticates to a new instance with the root password we set at creation. The
Go SSH client was public-key only, structurally. Adding password auth to the
agent was rejected: it puts password material through the exact path being
retired (web-writable table → root process), and costs an agent release plus a
fleet upgrade where a plane deploy would do.

**And the agent can no longer do the job at all.** SSH and SCP were removed from
it deliberately on 2026-08-30 — 397 lines across `ssh.go`, `scp.go`,
`runner.go`, `db.go` and `server.go` — as a forcing function, so that designs
stop being built around a transport we had already decided to delete.

## Design constraints

These are the point of the exercise. An executor that violates them rebuilds the
defect it was created to remove.

1. **Runs as the site user, never as root.** If a step needs root on the
   management node, that is a finding, not a requirement to satisfy.
2. **Takes work from in-process step lists.** `mjb_management_jobs` rows are
   **record and status only** — never a command source re-read by a privileged
   process. This is the constraint that actually closes the hole: a database
   write must not become an execution.
3. **Asynchronous, with per-step status writes**, so the existing job-detail
   page keeps working unchanged.
4. **Exactly two capabilities: local execution, and ssh/scp with ephemeral
   credential material.** Not a general-purpose runner. Anything else is a new
   capability and gets argued for on its own.
5. **Credentials live for the length of a job.** The claim-time `__SM_CREDS_`
   resolution moves in-process, which is simpler than the current hand-out, not
   harder.

## What moves onto it

From the WP2 audit in `agent_local_queue_retirement.md`, re-measured against
the source on 2026-08-30 after the removal. **Broken now** — the job is built,
dispatched, and fails at its first step:

| Operation | Why it needs the executor |
|---|---|
| ~~`restore_database`, `restore_project`, `restore_chain`~~ | **Closed 2026-08-30 — does not need the executor.** The destructive gate is open for a paired node on agent 1.13.0, and the artifact a restore had nothing to restore from now arrives over the channel too (`download_backup`, `stage_chain`). Needs the 1.13.0 agent published to the fleet; needs nothing from this spec. |
| `install_node` | **The one bootstrap.** Runs here over the sealed root password; collapses to a single SSH session per `ssh_single_bootstrap.md` |
| `provision_ssl` | **Does not move here — goes to the agent** (host node for a container, the node itself on bare metal). `ssh_single_bootstrap.md` |
| `enable_agent` | **Deleted, not moved.** `ssh_single_bootstrap.md` |
| `decommission_node` | Until it becomes a provider API call (custody WP3) |
| `provision_relay` | The relay has no agent, by A8. Moves to the provisioning runner, or dies with the relay — it also installs a restricted pull key on relay shards. |
| `rebuild_relay`, `relay_add_tenant`, `relay_set_domains`, `relay_remove_tenant` | **Delete at the relay cutover, not move.** Live code over dead data: no live tenant rows on this deployment. Disposition per `agent_local_queue_retirement.md`; an earlier draft of this table wrongly moved all five. |
| `backup_database`, `backup_project` | Orphaned handlers — **delete instead of moving** |
| `check_status`, `list_backups` on the disposable trio | The dispatcher is primitive → API → SSH (`build_check_status`:693). No agent by A8 rules out the first, and these three are **siteless, so there is no API route either** — SSH was the only one left. A node with API credentials still works. Monitoring is unaffected (`RunNodeUptimeChecks` is an HTTP/TCP probe, not a job); the node-detail status button is what stops working. |

**Not broken, contrary to the first draft of this table.** Both compose their
SSH as a `local` step that shells out from the management node, and the `local`
step type still executes:

| Operation | Status |
|---|---|
| `publish_upgrade` | Works today. It is a gate for retiring the queue (G1), not for this breakage. |
| `discover_nodes` | Works today, same reason. **Deleted, not moved** — `ssh_single_bootstrap.md` |

Also folded in: `ProvisionManagedDomains` and `ManagedDomainWatch`, which shell
out `ssh -i` outside the job system entirely. They become executor callers
rather than a separate migration — this is item 4 of the owner's five-step
delta, absorbed.

## What does NOT move

**The `local` step type does not survive the move.** Moving root `bash -c` into
PHP would be relocating the defect, not removing it. `publish_upgrade` is
plane-local work that happens to be expressed as a `local` step; it becomes a
first-class operation the executor knows how to run — `publish_upgrade.php` is
already a standalone CLI script — not an arbitrary command string.

**Primitives do not move.** They never touched SSH
(`primitives/dispatch.go:27`: "there is no ambient handle to the SSH pool") and
they keep going over the signed channel to each node's own agent. The executor
is for machines that cannot yet be reached that way, or that never will be.

## Work

**WP1 — ssh/scp capability.** Enough to run `install_node`, with password auth
(`sshpass` is present) as well as key auth for machines that supply their own.
`keyless_provisioning.md` WP1 needs **only this**, not publish integration — a
partial executor unblocks it.

**WP2 — Job lifecycle.** Async execution with per-step status and output writes,
so `job_detail` is unchanged. Rows record; they never command.

Death mid-job is settled here rather than discovered later, because it shapes
WP1's API surface. A job row carries its **parameters and status only** —
recovery never re-reads steps from the table, so the rebuild path stays code.
A stale-heartbeat watchdog marks a dead executor's job failed. Recovery is
human re-dispatch, which rebuilds the steps from code and parameters. And
execution runs in a **CLI worker**, never in-request, because deploys restart
PHP here as a matter of course.

**WP3 — `publish_upgrade` as a first-class operation.** Closes G1.

**WP4 — Migrate the remaining operations** in the table above; delete
`backup_database` and `backup_project` rather than porting them. Only
`install_node` migrates; every other operation in the table is deleted or
goes to the agent, per `ssh_single_bootstrap.md`.

**WP4a — Restore. WITHDRAWN 2026-08-30.** Restore went over the agent
channel instead; moving a dead builder onto a new executor would be building a
second transport for a path that already has one. See programme item 1.

**WP5 — Absorb the raw-SSH stragglers**, `ProvisionManagedDomains` and
`ManagedDomainWatch`. **Closed 2026-09-01 the other way:** both crossed to
the channel (`managed_domain_over_the_channel.md`) and neither is an
executor caller. The remaining raw-SSH straggler, `FleetProvisionSeeding`,
goes to the agent under `ssh_single_bootstrap.md` WP4.

**WP6 — Retire the local queue.** With nothing left emitting `local`, flip
`cfg.LocalJobs` false and delete the queue, the `local` step type, and every DB
method that fed it. The agent then holds no database credential at all.

## Acceptance

See the programme's single acceptance list. The criteria this spec is
responsible for are marked *(item 3)* and *(item 6)* there.

## Related

- `agent_local_queue_retirement.md` — the audit that enumerated the thirteen, and
  G1/G2
- `keyless_provisioning.md` — needs WP1
- `agent_management_first_principles.md` — the doctrine this serves
