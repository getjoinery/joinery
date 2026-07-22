# Job Teardown Phase

## What this is for

When a job copies a site, it makes working copies along the way — a database
dump, an archive of the site's files, an unpacked installer. Those are scratch:
the real data still lives where it was copied from. Today they are deleted by
ordinary steps at the end of the job, so they only get deleted when the job
reaches the end. A job that fails in the middle leaves them sitting on whatever
machine it touched, and the machine it touches most often is a shared
production host running other people's live sites.

This spec makes that cleanup unconditional: the working copies a job creates are
removed whether the job succeeds, fails, or is abandoned.

## Why now

Measured on 2026-07-22 while testing a site copy from `galactictribune` onto a
fresh VPS:

- The copy left 353 MB in the source container's `/backups` and another 353 MB
  staged on the shared docker host's `/tmp`. Nothing in the pipeline ever
  removed either — the existing cleanup steps only reached the target and the
  control plane.
- A *successful* install on 2026-07-19 (job 631) had already left 337 MB in the
  same place, untouched for three days.
- Of all `install_node` jobs to date, **15 failed and 13 completed**. The
  failure path is the common path, and it is the one with no cleanup at all.

Adding trailing cleanup steps — which is what was done for the source side —
fixes only the 13. The other 15 abort before ever reaching them.

## Current behaviour

The PHP side builds steps; a Go agent executes them. `Runner.Execute`
(`/home/user1/joinery-agent/runner.go:39-78`) walks the steps in order. On a
step failure without `continue_on_error`, it marks the job failed and returns:

```go
failMsg := fmt.Sprintf("Step %d (%s) failed: %s", i+1, step.Label, err.Error())
if failErr := r.db.FailJob(job.ID, failMsg); failErr != nil { ... }
return
```

That bare `return` skips every remaining step. There is no teardown, finally, or
always-run concept anywhere in the agent, and no such key on the `Step` struct
(`/home/user1/joinery-agent/db.go:31-45`). `continue_on_error` protects a step
against *its own* failure only; it does nothing for the steps after a hard
failure.

`RecoverStaleJobs` (`db.go:235`) force-fails any job left `running` when the
agent restarts. Those jobs never run their cleanup either, and never will.

## The rule for what may be marked teardown

Only **scratch** may be torn down. Scratch is an intermediate the job created
purely to move data — dumps, staged archives, unpacked installers, generated
helper scripts — where the thing it was copied from still exists.

Two kinds of file look like scratch and are not:

1. **A real artifact deleted as a policy outcome.** "Clean up local backup" in
   the offsite-backup job (`JobCommandBuilder.php`, the
   `mgn_delete_local_after_upload` step) removes a node's actual backup file
   after upload. Its guard is deliberate and documented on the upload step
   above it: if the upload fails, halting is what stops the job deleting the
   only surviving copy. Marking that step teardown would destroy customer
   backups on exactly the runs where the upload failed. It stays as it is.
2. **The job's deliverable.** A file can be a copy and still be the product.
   The publish-upgrade job exists to place release archives in the control
   plane's upgrade repository — deleting them at job end would make the job a
   no-op. The deliverable's lifecycle belongs to whatever consumes it, never
   to the job's teardown.

Stated as a test: *if this step ran the moment the job ended — on success or
after a mid-job failure — could it destroy data that exists nowhere else, or
the thing the job was run to produce?* If either, it is not teardown.

## Design

### Marker on the step, not a separate list

A step carries `"teardown": true`. It stays in the same `steps` array it is in
today.

This is deliberate for rollout. A moved-to-its-own-array design would be
invisible to an agent that has not been upgraded yet, so cleanup would silently
stop happening on every node still running the old binary. With a flag on the
step, an old agent ignores the unknown key and runs it in sequence exactly as it
does today, while an upgraded agent hoists it into the teardown phase. No
window where the fleet is worse off than it is now.

**Placement: teardown steps go at the tail of the array, after every main
step.** The compatibility story above is only true if sequential execution of
the array is still correct — an old agent runs teardown steps exactly where
they sit, so a teardown step placed next to the step that creates its artifact
would delete the artifact before use on every un-upgraded node. Tail placement
gives old agents today's trailing-cleanup behaviour and new agents the full
guarantee. The builder test asserts no main step follows a teardown step.

### Execution

`Runner.Execute` partitions steps into main steps and teardown steps,
preserving order within each. The main loop runs only the main steps and keeps
its current semantics unchanged. The teardown steps then run on every exit
path — success, hard failure, and the empty-main-steps case.

**Teardown runs after the main loop determines the outcome but before
`FailJob`/`CompleteJob` writes it.** The job stays `running` while teardown
executes, which does two things: the per-node concurrency lock in
`ClaimNextJob` stays held, so no successor job (a re-run, typically) can start
on the node while teardown is still deleting files there; and the job detail
view keeps streaming teardown output, since pollers stop at a terminal status.
The cost is that an agent dying *during teardown* leaves the job for stale
recovery's generic message — acceptable, because the original failing step is
still in the output as the `[FAILED: ...]` line, and teardown is a handful of
short `rm` commands.

Teardown steps behave as if `continue_on_error` were set: one failing does not
stop the rest. Their output is appended under a `=== Teardown ===` header so it
is visible in the job detail view. A teardown step aimed at a machine the job
never reached (a target that failed before it existed, an unreachable host)
fails like any other — logged under the header, the rest continue. That is
expected on failed jobs, not something to suppress.

**Teardown steps carry a short explicit timeout (120s).** They are `rm`
commands; the default 30-minute step timeout would let one wedged connection
hold the job — and, under the ordering above, its node lock — open for half an
hour.

### Teardown never changes the outcome

A job that failed stays failed; a job that completed stays completed even if a
teardown step errors. The job's error message keeps naming the original failing
step — teardown must never overwrite the reason the job failed, because that
reason is what someone is reading the job detail to find.

Concretely: the main loop determines the outcome and (on failure) the exact
`failMsg` it produces today; teardown runs, writing only to output; then
`FailJob`/`CompleteJob` is called with that held outcome, unmodified.

### Progress counting

`mjb_total_steps` counts main steps only — it is set from `count($steps)` in
`management_job_class.php` today and changes to count non-teardown steps — so
the progress a person watches still reflects the work, and teardown does not
make a failed job look like it got further than it did. `AppendOutput` writes
`mjb_current_step` on every call, so teardown appends pass the index the main
loop ended at rather than advancing it.

### Idempotency

Every teardown command must be safe to run more than once — `rm -f` for files,
`rm -rf` for directories, never a bare `rm` — because re-running a job replays
them and the recovery path below replays them again.

### Unique paths

Every scratch path is derived from a per-job unique id, so no teardown step can
collide with a concurrent or successor job's files. This is the collision
guarantee — not lock timing, which only covers jobs on the same node. One
existing path violates it: the unpacked installer at `/tmp/joinery_install`
(`JobCommandBuilder.php:1373`) is fixed, so a failed install's teardown could
delete it out from under a later install already using it. This spec renames it
to `/tmp/joinery_install_{transfer_id}` (the definition and its references,
including the existing success-path removal step).

### Abandoned jobs

`RecoverStaleJobs` force-fails jobs that were `running` when the agent
restarted. Because the teardown steps are stored on the job in `mjb_commands`,
they are replayable: after marking those jobs failed, the agent runs their
teardown steps. This is the same list, executed by the same code path — not a
separate sweeper with its own idea of what counts as garbage.

Replay is safe by construction for the scratch class: the agent cannot know how
far a stale job got, but removing a scratch path that was never created is a
no-op, and every path is per-job unique. The builder test below is what keeps
future teardown steps inside that class. Replay does inherit an assumption
`RecoverStaleJobs` already makes — that the restarting agent is the only
executor, since it force-fails every `running` job with no ownership check. One
agent per control-plane database is the current deployment shape; a second
agent sharing a database is unsafe today for reasons that predate this spec.

## Inventory — every step to mark

Decided once here rather than per-job, so a new job type has a rule to follow
instead of a precedent to copy.

| Job builder | Scratch it creates | Machines |
| --- | --- | --- |
| `build_install_node` (from-backup, `backup_source = new` only) | `/backups/install_{id}.sql.gz`, `/backups/install_{id}_project.tar.gz` | source (container) |
| | `/tmp/install_{id}.sql.gz`, `/tmp/install_{id}_project.tar.gz` | source host, control plane |
| `build_install_node` (all from-backup) | `/tmp/joinery_restore_{id}.sql.gz`, `/tmp/joinery_restore_{id}_project.tar.gz` | target, target host |
| `build_install_node` (all) | `/tmp/joinery_install_{id}` | target |
| `build_copy_database` | `/tmp/copy_{id}.sql.gz` | source, source host, target, target host, control plane |
| `build_copy_database_by_name` | `/tmp/local_copy_{id}.sql.gz` | node |
| node scan (`build_discover_nodes`) | `/tmp/joinery_discover_{id}.sh` | control plane |

When `backup_source` names an **existing** backup instead of `new`, the
`/backups/...` paths on the source are the user's real backup files — the job
did not create them and must not tear them down. Only the staged and
target-side copies are scratch in that variant. (The `backup_source === 'new'`
guard on the source-cleanup steps added 2026-07-22 already encodes this.)

Explicitly **not** teardown: "Clean up local backup" in the offsite-backup job
(retention — rule 1 above), the publish-upgrade job's release archives (the
deliverable — rule 2 above), and the relay tenant removal step in
`build_relay_remove_tenant`, which is the job's actual purpose rather than
cleanup.

## Changes

**Agent** (`/home/user1/joinery-agent/`)

- `db.go`: add `Teardown bool \`json:"teardown,omitempty"\`` to `Step`.
- `runner.go`: partition steps; run teardown on all exit paths, after the
  outcome is determined and before it is written; teardown output under its own
  header without advancing `mjb_current_step`; teardown failures logged, never
  promoted to job failure. Structure the partition and exit-path decision as
  logic testable without a live database or SSH — this is the agent's first Go
  test, so the seam has to be built, not found.
- `db.go`: `RecoverStaleJobs` returns the affected job ids and their commands
  so their teardown can be replayed.
- `main.go`: bump `version` from its current value; the agent version is
  reported into `ahb_agent_version`, so the fleet view shows which nodes have
  the new binary.
- Rebuild and redeploy to the fleet.

**PHP** (`plugins/server_manager/`)

- `includes/JobCommandBuilder.php`: mark the steps in the inventory above with
  `'teardown' => true` and `'timeout' => 120`, placed at the tail of each
  builder's array. The two source-cleanup steps added on 2026-07-22 stay where
  they are and gain the flag.
- `includes/JobCommandBuilder.php`: rename `/tmp/joinery_install` to
  `/tmp/joinery_install_{transfer_id}` (see Unique paths).
- `data/management_job_class.php`: `mjb_total_steps` counts only non-teardown
  steps.

## Testing

- `plugins/server_manager/tests/job_command_builder_test.php`:
  - For each job builder in the inventory, assert every scratch path it
    creates has a matching teardown step. This is the test that stops the next
    job type from quietly reintroducing the leak.
  - Assert no main step follows a teardown step in any builder's output (the
    old-agent placement guarantee).
  - Assert the offsite-backup retention step is *not* marked teardown.
  - Assert `build_publish_upgrade` produces no teardown step touching its
    release archives (the deliverable).
  - Assert the from-existing-backup install variant produces no teardown step
    touching the named backup paths.
- Agent-side test for the partition and exit-path behaviour: a job whose second
  of three main steps fails must still run its teardown steps, must end
  `failed`, and must keep the original error message.
- Live gate: run an install against a scratch VPS with a deliberately failing
  mid-job step, then assert no `install_*` artifacts remain on source container,
  source host, target, or control plane.

## Docs

Update `plugins/server_manager/docs/overview.md` — the job execution model
section — to describe steps as having a main phase and a teardown phase, and to
state the rule for which cleanup belongs in which. Written as the current
design, with no reference to the trailing-step arrangement it replaces.
