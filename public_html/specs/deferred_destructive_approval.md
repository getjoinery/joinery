# Deferred destructive approval — stop holding the job open while a person decides

**Status: SPEC, unbuilt. Written 2026-08-30.** Follows
`implemented/restore_dispatch_approval_mechanism.md`, which built the approval
and holds the job open for its duration. This removes the holding, and with it
the ceiling on how long an operator may take.

## The problem, in plain terms

A restore over the agent channel waits for a human on the node's own site to
approve it. Today the node **holds the job open** for the whole wait, and the
agent runs exactly one job at a time (`jobLock` is held across
`runner.Execute`). So every minute of the approval window is a minute the node
answers nothing else: no backup, no status check, no upgrade.

That is why the window is an hour and not a day. It is not a limit on how long
an approval is safe to leave outstanding — it is a limit on how long a machine
can afford to be deaf. The number was set from the wrong side of the problem.

**The requirement it fails is ordinary: the person who must approve is at work.**
A restore is a decision someone makes when they can, not within the hour it was
dispatched. Stretching the current design to twelve hours would take a managed
node out of monitoring for half a day, and — because the plane's claim budget
must exceed the node's own deadline — delay noticing a genuinely crashed restore
by the same twelve hours. The latitude is bought by making the fleet blind.

## What changes

**The node stages the challenge and lets the job go.** It does not sit on it.

1. The agent claims a destructive job, composes its statement, seals the
   challenge to its own recovery key, stages it — all exactly as now — and then
   **releases the job** in an `awaiting_approval` state rather than blocking.
2. The operator answers whenever they get to it. Hours later is fine.
3. On a later poll the agent sees a staged answer, re-claims that same job,
   **re-derives the statement**, verifies the answer against it, and runs.

The expiry becomes a property of the challenge alone — 12 or 24 hours, an
operator-facing number — instead of a number the node's availability has to pay
for.

## Why this is safe, and mostly already proven

**The binding does the work, and it already exists.** The answer is sealed to a
specific job id *and* to a hash of the statement. Nothing new has to be invented
to make a deferred answer safe:

- An answer for one job cannot satisfy another. Already true, already tested.
- **An answer cannot survive the world changing underneath it.** If the archive
  is replaced while the operator is at work, the re-derived statement hash
  differs and the answer no longer matches, so the restore refuses. That is the
  correct outcome and it falls out of the existing construction rather than
  being added — the same property that made a backup landing mid-window a
  non-event once `verify()` returned the matched version.
- The management node stays out of both halves. Nothing here gives it a role it
  does not have today; the answer still never crosses the wire.

**What genuinely needs deciding, rather than inheriting:**

- **A job state the plane will not requeue.** `awaiting_approval` must be
  distinct from claimed and from failed. A requeue during the wait would issue a
  second challenge, and a second challenge silently invalidates the one the
  operator is looking at.
- **Whether an unanswered job eventually fails.** It should: an approval nobody
  answers in 24 hours is a decision not to approve, and a destructive job that
  lingers indefinitely is a trap for whoever finds it next.
- **What the operator sees for a job that is waiting.** Today "awaiting
  approval" is invisible on the dashboard, because the job simply looks claimed
  and slow. Deferred, it becomes a state that can last a working day and must be
  legible from the fleet list.

## What this fixes beyond the window

- **Claim budgets go back to being about the work.** They currently carry the
  approval window (`restore_database` is 70m of restore plus 60m of waiting).
  Once the wait is outside the claim, they describe the restore again, and a
  crashed restore is noticed on the work's own timescale rather than the
  operator's.
- **A node is never deaf because a person is busy.** Backups, status and
  upgrades continue while an approval is outstanding.
- **The window stops being a compromise.** It can be set to whatever is right
  for a human — a working day — because nothing else depends on it.

## Acceptance

- A destructive job dispatched, staged, and left unanswered for longer than the
  current approval window still runs when it is approved.
- While it waits, the same node completes an ordinary job — a status check or a
  backup — proving the queue is not blocked.
- An answer given after the underlying archive changed is refused, naming the
  reason.
- A job nobody answers within the challenge's lifetime fails as a refusal, and
  says nobody answered rather than that something broke.
- The plane never requeues a job that is awaiting approval, and never issues a
  second challenge for one. Pinned by a test, because a second challenge would
  silently invalidate the screen an operator is looking at.
- Every claim budget still exceeds every agent timeout
  (`primitive_transport_parity_test.php`) — with the approval window no longer
  inside either.

## Related

- `implemented/restore_dispatch_approval_mechanism.md` — the mechanism this
  defers, including why the plane is absent from the approval path
- `agent_management_first_principles.md` — programme
