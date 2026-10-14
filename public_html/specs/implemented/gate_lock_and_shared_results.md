# Gate Lock and Shared Results

Status: IMPLEMENTED 2026-10-14. WP1-WP4 built: tests/lib/gate.php, tests/run.php,
tests/lib/coverage.php, tests/index.php, tests/unit/gate_lock_test.php (48 checks),
docs/testing.md, and the CLAUDE.md sentence through the agent-files record.
Verified on dev: the wait, --no-wait, a killed holder freeing the gate, reuse
across runs, refusal when a loaded file differs, the dashboard holder line, and
a full db --changed gate run under the lock at nice 10.

Scope is the dev box only: one gate at a time, and a second agent borrowing
the first gate's results where its own tree would produce the same ones.
Running the gate elsewhere (a Linux VM on the Mac mini) was assessed on
2026-10-14 and is deliberately out of scope here.

## The problem in plain terms

Several agents share one 4-core box. Each of them runs the test gate whenever it
is about to check in, and nothing stops three of them from starting one in the
same minute. A gate is already a three-wide pool plus a test-database lane plus
a cargo build, so three gates are nine-plus PHP processes and three compilers
on four cores: the box goes to a load of 10 to 12, every session becomes
unresponsive, and on 2026-10-05 it ran out of memory and lost every session.

The overlap also makes the gate lie. Most of the flaky reds recorded in the
flaky-suites note (manifest_licensing B21, the eight unrelated failures on
10-08, the host_report timing check) happened only when two runs overlapped.

And the gate is expensive: about twelve minutes for the full `db` batch. Two
agents whose trees differ only in files most suites never load run the same
twelve minutes twice and learn the same thing twice.

## What changes for people

- **Only one gate runs on the box at a time.** A second `php tests/run.php`
  waits, says who holds the gate and how far along it is, and starts the
  moment the holder finishes. A quick scoped run (a handful of suites) does
  not queue.
- **A gate runs at low CPU priority**, so the sessions on the box stay
  responsive while it runs.
- **An agent can take another run's results instead of re-running.** Every
  run records exactly which tree it tested and which files each suite
  reached. A later run reuses a suite's result when none of the files that
  suite reached differ between the recorded tree and the current one, and
  runs only the rest. The output names every reused result and the run it
  came from.
- Nothing changes about what a full gate means. `php tests/run.php db` with
  `--fresh` is still the complete pre-publish proof, run now, on this tree.

## Design

### D1. One gate per box: a lock, held for the run

The runner takes an exclusive `flock()` on `{site root}/cache/tests/gate.lock`
before it runs its first suite and holds it until its last result is in. The
kernel releases the lock when the process dies, so a killed runner leaves no
stale lock and no cleanup step.

While waiting, the runner prints one line naming the holder, then a progress
line every 30 seconds read from the holder's run record (D4): the holder's
tier and arguments, suites completed out of selected, failures so far, and
time held. `--no-wait` exits with code 3 instead of waiting, for callers that
would rather come back later.

**What takes the lock.** Every run that executes suites, except:

- a run whose selection is at most 10 suites (a `--only`, a narrow `--filter`,
  or a `--changed` that reaches little). It runs at once, beside whatever holds
  the gate, so the working loop never queues behind a twelve-minute gate;
- the `deploy` tier, which runs once on a node after an upgrade swap with a
  rollback hanging on the result, where a wait is the wrong answer;
- `--list` and a run that selects nothing.

The cutoff is one constant in the runner, named and explained where it is
declared. Ten is a guess at "finishes in seconds"; the first week of use
corrects it.

The dashboard at `/tests/` runs one suite per request, so its runs are always
below the cutoff and never queue. It shows the holder line from the run record
at the top of the page while a gate is running.

**Nested runners.** A test of the runner itself spawns runners while the outer
runner holds the lock. The environment variable `JOINERY_GATE_DIR` relocates
the lock file and the run records to a directory of the caller's choosing;
the runner's own test sets it to a temp directory. It is read only when the
`debug` setting is on.

### D2. The holder is named

The lock file is a lock, not a record. Beside it the runner writes
`gate.holder.json` the moment it takes the lock: pid, start time, tier,
arguments, selected-suite count, and the Claude session id from
`CLAUDE_CODE_SESSION_ID` when the environment carries one. The `work`
launcher's registry (`~/.claude/work/sessions.json`, outside the repository)
maps a session id to its name; the runner resolves the name when that file is
readable and prints the id otherwise. A reader checks the holder file against
the lock (`flock` with `LOCK_NB`) before believing it, because the file can
outlive a holder that was killed.

### D3. A gate runs at low priority

The runner raises its own nice value to 10 at start (`proc_nice()`), and every
suite subprocess, the lane worker and any compiler they start inherit it. The
box's interactive sessions win the CPU; the gate takes what is left, which on
a quiet box is everything. Postgres is not niced, so the database work the
gate waits on is unchanged. No I/O class is set: the gate's I/O is Postgres's,
not the runner's.

### D4. Every run leaves a record of what it tested

A run writes `{site root}/cache/tests/runs/{run_id}.json` when it starts and
appends one JSON line per completed suite to `{run_id}.jsonl` as results land
(the same records the aggregate carries, so the waiter in D1 and the dashboard
read progress from it). `run_id` is the start time plus the pid. At finish the
`.json` is rewritten with the aggregate totals and `finished`.

The record's two identity fields:

- **`tree`** — the git tree id of the working tree as it stood when the run
  started: every tracked and untracked, non-ignored file under the repository
  root, which covers `public_html/` and `maintenance_scripts/` and leaves out
  `vendor/` and `sync/target/`. It is made with `git add -A` into a temporary
  index (`GIT_INDEX_FILE`) and `git write-tree`, so the shared index and the
  per-session prepared-commit indexes are never touched. The tree object stays
  in the repository as an unreachable object, which is what makes the diff in
  D5 possible later; git prunes such objects after two weeks, longer than any
  record lives.
- **per-suite `reach`** — the repo-relative files the suite loaded (the
  harness's `files` from its result contract) plus its header's `covers`
  globs, as the coverage map already records them. The reach is the statement
  "this result depends on these files and no others".

A suite's result record also carries `reach_complete`: true for a PHP suite
whose process reported its files and for a shell gate (its `covers` is its
whole declaration by rule), false for a suite that crashed or timed out
before reporting, for a suite with `needs: [dev-web]` (Apache-side reach is
not in the process's include list), and for `live`, `prod-verify` and `deploy`
tier suites, which are never reused.

Records older than 48 hours are deleted at every runner start.

### D5. Reuse: take a result when its reach is unchanged

Before running its selection, a runner (unless `--fresh`) looks through the
run records on the box, newest first, for each selected suite. A recorded
result is **valid for this tree** when:

1. `reach_complete` is true and the result is `pass` or `fail` (not a skip,
   crash or timeout);
2. none of the suite's reach files differ between the record's `tree` and
   this run's `tree`, which one `git diff-tree -r --name-only A B` per record
   answers for every suite at once (a `covers` glob is matched against that
   list);
3. the record's `debug_env` equals this run's.

The newest valid record wins. The suite is then not run: its result record is
copied into this run's results with `reused_from = {run_id, started, holder}`
added, counted in the totals exactly as a fresh result would be, and printed
as

    ✓ booking_flow  (reused: run 2026-10-14T18:40 by public-html-41, 8.1s)

The summary states `N of M results reused from K runs; M−N run now`. The
JSON contract carries `reused_from` per result and a `reuse` block in the
aggregate. A reused result does not touch the coverage map: the map already
holds exactly that reach from the run that produced it.

Reuse works against finished runs and against the run holding the gate.
While the runner waits for the lock it reads the holder's `.jsonl` as it
grows; when the lock is released it decides reuse against everything the
holder completed, takes the lock, and runs only what is left. A run that
needs nothing after reuse releases the lock at once and reports.

**What reuse does not know.** It knows what a suite loaded; it does not know
what the database held, what a setting was, or what another process wrote
while the suite ran. That is the same assumption `--changed` makes today
when it skips a suite, and the same one a `db`-tier suite makes about dev's
rows. A result that was wrong for one of those reasons stays wrong when
reused. `--fresh` is the answer whenever that matters, and the full
pre-publish gate is always run with it (D6).

### D6. The pre-publish gate is fresh

The full `php tests/run.php db` that precedes a publish is run with
`--fresh`: a release proves the tree it ships, on the box, now. Reuse is for
the working loop and the pre-checkin gate, where the question is "did my
change break something", not "is this tree sound". The publish script itself
runs only the `deploy` tier (`PublishTestGate::run($runner, 'deploy', ...)`),
which D1 exempts from the lock and D4 never reuses, so it is unchanged.

### D7. Flags

| flag | meaning |
|---|---|
| `--fresh` | run every selected suite; look at no record |
| `--no-wait` | if the gate is held, exit 3 at once instead of waiting |
| `--serial`, `--only`, `--filter`, `--changed` | unchanged; `--only` and a small `--filter` fall under the cutoff and never queue |

No flag turns reuse on: it is the default for every run that takes the lock,
and `--fresh` turns it off. A run below the cutoff reuses too; a reused
`--only` prints where the result came from, which is the honest answer to
"does this pass on my tree".

## Work packages

- **WP1 — lock and priority (D1, D2, D3).** `tests/run.php`: `flock` around
  the batch, the holder file, the wait loop with progress, the cutoff, `--no-wait`,
  `JOINERY_GATE_DIR`, `proc_nice(10)`. The dashboard holder line
  (`tests/index.php` and the `tests_run` action).
- **WP2 — run records (D4).** `tests/lib/coverage.php` gains the tree id
  helper and the reach record; `tests/run.php` writes `.json` / `.jsonl` as
  results land, prunes old records.
- **WP3 — reuse (D5, D6, D7).** The record search, the per-record
  `diff-tree`, `reused_from`, the summary and JSON changes, and `--fresh`.
- **WP4 — docs.** `docs/testing.md`: a section on the lock and the cutoff, a
  section on run records and reuse, the flags table, and the cost paragraph
  rewritten to say what reuse changes. CLAUDE.md's "Which tier, when"
  paragraph (through the admin agent-files record) gains one sentence: a
  queued gate waits by design, and the pre-publish gate is `db --fresh`.

Each package ships with its test (below) and runs the gate before it is
prepared for commit. WP1 is worth committing on its own: it ends the freezes
without the other three.

## Open questions

- **Q1 — the cutoff.** Ten suites is the starting guess. If `--changed` runs
  routinely select 11 to 30 cheap suites and queue behind a full gate, raise
  it; if a 10-suite run with `sync_sim` in it hurts a running gate, weigh
  suites by their declared `timeout` instead of counting them. Decide from a
  week of the holder log, not now.
- **Q2 — holder name without `work`.** A session started outside the `work`
  launcher has no name in the registry. The runner prints the session id;
  whether `work` should also take a label from the runner is `work`'s
  business and not part of this spec.

## Testing

`tests/unit/gate_lock_test.php` (tier `safe`, `needs: []`), running with
`JOINERY_GATE_DIR` pointed at its own temp directory and a two-suite fixture
estate under `tests/unit/fixtures/gate/`:

- **One gate at a time.** Start a runner on a fixture suite that sleeps;
  start a second; the second's output names the holder and the first's
  progress, and its first suite starts after the first runner's last result.
- **Below the cutoff runs at once.** A one-suite `--only` against a held gate
  finishes before the holder does.
- **`--no-wait`** exits 3 with the holder named, running nothing.
- **A killed holder frees the gate.** SIGKILL the holder; the waiter proceeds
  within a second and the stale holder file is not believed.
- **The record is written** with a `tree` that `git cat-file -t` calls a
  tree, and per-suite reach for a PHP fixture and `covers` for a shell one.
- **Reuse when nothing reached changed.** Run, touch a file outside the
  fixture suite's reach, run again: the second run reuses, names the first
  run, and executes nothing.
- **No reuse when a reached file changed.** Edit a file in the reach; the
  second run executes the suite and the result carries no `reused_from`.
- **Never reused:** a skipped result, a timeout result, a `needs: [dev-web]`
  suite with an otherwise identical reach, and any record under `--fresh`.
- **Priority.** The suite process's nice value, read from `/proc/self/stat`
  inside the fixture suite, is at least 10.

The test holds no real lock: the outer gate runs it like any other suite.
