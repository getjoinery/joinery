# Test-DB Lane Overlap

## Problem

The pre-deploy gate (`php tests/run.php db`) runs every test one at a time.
About 55 seconds of it — `models_crud`, `multi_models_crud`,
`model_tester_selftest`, `recovery_readiness` — runs against the **copied test
database**, not the live dev database, yet still runs serially after
everything else. The gate is ~4.2 minutes; hiding that lane inside the db
batch's wall clock makes it ~3.3.

A general parallel runner was considered and rejected: it required auditing
~150 tests for isolation and keeping those declarations true forever, and the
thing it risked was trust in a red gate. This spec is the surviving piece —
**one** carefully-reasoned exception, no per-test declarations, no audit.

## Why this overlap is safe by construction

The `test-db` suites' writes all go through `DbConnector::set_test_mode()`
(`includes/DbConnector.php`), which opens a second connection to
`dbname_test` and routes queries there until `close_test_mode()`. ModelTester
cycles it once per class; `recovery_readiness` enters it via
`harness_test_mode()` at boot. Nothing in the db tier touches `dbname_test`;
nothing in the test-db lane writes the dev database. The two lanes share a
Postgres server and nothing else that is written.

This is enforced, not assumed: `harness_finish()` fails any `test-db`-tier
suite whose process never entered test mode
(`DbConnector::test_mode_was_used()`), so a future lane suite that forgets the
switch — or a refactor that drops it — fails its own run rather than silently
writing to the live database. That guard exists because exactly this bug was
found during review: a lane suite declared `tier: test-db` and believed it ran
on the copy, but never switched.

Residual contact, stated honestly:

- **Boot-time reads of the live DB.** A test-db suite's `harness_boot()` reads
  settings (`stg_settings`) and framework state from the dev database once at
  process start. A db-tier test that temporarily rewrites a settings row could
  in principle be seen by a test-db process booting inside that window. The
  window is boot only — after boot the suites talk to the copy — and the
  db-tier tests that mutate settings restore them via `harness_defer`.
  Accepted risk; if a flake is ever traced here, the fix is starting the
  test-db lane before the db batch rather than alongside it.
- **The copy itself is shared within the lane.** The four suites stay serial
  *inside* the lane — two of them concurrently would race on the copy.
- **Copy refreshes are out of band.** The test-database refresh (admin Test
  Database page) is not part of `run.php` and must not run during a gate; that
  is already true today and this spec does not change it.

## Shape

In `tests/run.php`, when a cumulative run reaches the db batch:

1. Spawn one child worker that runs the four `test-db` suites serially, in
   their current order, each as its own PHP process exactly as today.
2. Run the db batch serially in the main loop exactly as today.
3. Join: collect the lane's results (each suite's stdout parsed from its own
   pipe, same sentinel contract), merge into the aggregate.

Result lines print in completion order; they already carry their tier tag, so
interleaved `[db]` / `[test-db]` lines stay legible. The aggregate JSON
contains the identical test set with the identical shape — only durations and
ordering differ. A lane failure fails the gate exactly as it does today.

Degenerate cases collapse to current behavior:

- `--filter` / `--only` selecting tests from only one lane runs serially,
  as today.
- `php tests/run.php test-db` alone runs the lane serially with no overlap.
- A `--serial` flag forces today's behavior end to end, for debugging and as
  the fallback if the overlap ever misbehaves.
- `safe`, `live`, and `deploy` invocations are untouched.

The runner-side probe that checks the test-db connection before declaring the
lane runnable keeps its current semantics (skip-with-reason when the copy is
absent); the probe runs before the fork so an unrunnable lane never spawns.

## Acceptance

- `php tests/run.php db --serial` produces the same aggregate JSON as the
  current runner (modulo durations).
- The overlapped run's aggregate contains exactly the same test set and
  per-test pass/fail/skip results as a serial run of the same tree.
- A failure in either lane fails the gate; a crash of the lane worker is
  reported as failures for its unrun suites, never silently dropped.
- Gate wall clock drops by roughly the test-db lane's duration (~55s at
  today's timings).
- The harness guard holds: a `test-db`-tier suite that never enters test mode
  fails its run. (Already live; the overlap depends on it staying true.)

## Documentation

When built, update `docs/testing.md`: the "What each tier costs" section is
re-measured, and the runner section states that the test-db suites run
alongside the db batch (with `--serial` as the opt-out) and why that overlap
is safe — the lane writes only to the copied test database.
