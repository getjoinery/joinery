# Parallel Test Runner

## Problem

The pre-deploy gate (`php tests/run.php db`) runs every test one at a time, one
PHP process each. Most of its wall clock is spent waiting on the database, not
on CPU — so the gate's duration is the *sum* of its tests even though the
machine could overlap most of them. After the 2026-08 single-test optimizations
(plugin_sync, job_command_builder, sleep trims) the gate still costs minutes,
and every test added to the estate makes it linearly slower. A gate that costs
too much stops being run before checkins, which is the failure that matters.

The runner already has the right unit of isolation: each test is its own
process with its own framework boot, emitting a self-contained result contract
on stdout. Nothing about the contract requires the processes to run one at a
time. What *does* require care is that db-tier tests share the live dev
database and a handful of other singletons.

## Shape

A worker pool inside `tests/run.php`. The runner launches up to N test
processes concurrently (`proc_open` + non-blocking reads), collects each
process's stdout privately, and parses the same result sentinel it parses
today. Output and the human-readable per-test lines are printed in completion
order; the aggregate JSON contract is unchanged. `--workers=N` overrides; the
default comes from a new `concurrency` view of the estate described below.

Sequential remains a first-class mode: `--workers=1` must behave exactly as
the runner behaves today, and is the fallback whenever anything about a run
looks parallel-unsafe (see Rollout).

## The isolation problem, stated honestly

Tests in the `db` tier write to the shared dev database. Most are already
parallel-tolerant by construction — the estate's convention is fixtures with
random suffixes (`bin2hex(random_bytes(...))`), cleaned up via
`harness_defer()`. But "most" is not a property the runner can act on. The
known hazard classes, from the 2026-08 estate audit:

1. **Whole-subsystem mutators.** `plugin_sync` runs the real
   `PluginManager::sync()` — schema DDL, settings seeding, menu rebuilds.
   Nothing else may share the database while it runs.
2. **Shared-row mutation.** Tests that UPDATE settings rows, toggle plugin
   active flags, or manipulate well-known users (user 1) race any concurrent
   reader of the same state.
3. **Unscoped writes against shared tables.** `tests/functional/drive/changes_test.php:130`
   deletes `fch_file_changes` rows by id range — it destroys other tests'
   rows today and must be fixed regardless of this spec.
4. **Process-external singletons.** Tests that assert on APCu keys they don't
   own, Postfix/relay spool state, or the web server's error log tail.
5. **The test-db copies.** `test-db` suites rebuild/copy the test database;
   two of those at once is a corruption generator.
6. **HTTP-tier tests.** Tests driving `https://dev.getjoinery.com` over curl
   are parallel-safe with each other *if* their server-side fixtures are
   suffixed (they are), but they share Apache/php-fpm worker capacity — they
   parallelize, just with diminishing returns.

## Design: declared concurrency, conservative default

Extend the `@joinery-test` header with one optional key:

```
 * concurrency: parallel | exclusive
```

- `parallel` — the test declares its fixtures are self-contained: suffixed
  rows, `harness_defer()` cleanup, no mutation of shared rows/settings/schema,
  no assertion over global tables it didn't populate.
- `exclusive` — the test needs the database (and other shared singletons) to
  itself. The runner drains the pool, runs it alone, then resumes.
- **Absent — treated as `exclusive`.** Nothing speeds up until a human has
  audited a test and said so in its header. A wrong `parallel` declaration is
  a flaky gate, which is worse than a slow one; the default must fail safe.

The runner schedules greedily: maintain the pool at N with `parallel` tests;
when an `exclusive` test reaches the front, wait for the pool to empty, run it
alone, continue. Tier boundaries (`safe` → `db` → `test-db`) remain barriers —
cumulative runs keep their current ordering guarantees. All `test-db` suites
are implicitly exclusive regardless of header (rule 5); `live` and `deploy`
tiers are out of scope and always sequential.

Enforcement, not just convention: the header parser rejects an unknown
`concurrency` value the same way it rejects an unknown tier — fail-closed,
because a typo like `concurency:` silently defaulting to exclusive is fine,
but `concurrency: paralell` must not be silently *anything*.

### What N should be

Default `min(4, cores/2)` for the first release. The bottleneck being Postgres
and Apache rather than CPU means returns flatten quickly, and modest N keeps
worst-case interference low while the `parallel` set grows. Revisit upward
once the audit has covered the estate.

### Scheduling detail worth pinning

Longest-first among ready `parallel` tests. The gate's tail is set by its
longest tests; starting them early means the pool's stragglers overlap the
short tests instead of running after them. The runner already records
`duration_ms` per test per run — persist the last run's durations under
`tests/.last_durations.json` (gitignored) and use them as the sort key,
falling back to file size order for never-run tests.

## The audit (the actual work)

The code change is small; the value is the per-test audit that earns
`concurrency: parallel` headers. Audit order, by payoff:

1. The under-a-second majority (~150 tests, ~23s serial): unit-style checks on
   pure logic. Most qualify immediately.
2. The API/HTTP group (`tests/functional/api/*`, ~35s serial): suffixed
   fixtures over HTTP; expected to qualify.
3. The mid-cost mailbox/vault/drive suites: each needs individual reading —
   several assert on tables they treat as theirs (`iem_inbound_email_messages`
   counts, APCu vault keys for fixture users are fine; watch for tests that
   scan whole tables).
4. Never: `plugin_sync`, `declared_settings`, `plugin_settings_tab` (plugin
   metadata/settings mutators), anything asserting on the error log, the
   `test-db` tier.

Fix as part of the audit, not as a precondition to it:
- `changes_test.php` unscoped DELETE (scope to the fixture's file ids).
- Any test found asserting COUNT(*) over a shared table without pinning to its
  own fixture rows.

## What this does NOT change

- The result contract, `--json` aggregate shape, `--filter`/`--only`, and the
  fail-closed tier parsing.
- The `/tests/` dashboard "Run all" (client-side, one request at a time) —
  browser-driven runs stay serial; the dashboard can pass `--workers` later if
  wanted.
- The `deploy` tier: runs on production, stays sequential, pulls in nothing.
- Test authorship rules: the harness, headers, and `tests/{area}/` layout are
  untouched except for the one new optional header key.

## Acceptance

- `php tests/run.php db --workers=1` produces byte-identical aggregate JSON
  (modulo durations) to the current runner.
- `php tests/run.php db` with the audited `parallel` set green 5 consecutive
  runs with zero flakes before the default N goes above 1 on any machine.
- An `exclusive` test never overlaps any other test (assertable in the runner
  with a lock file the workers touch; a violation is a runner bug and fails
  the run loudly).
- A test with `concurrency: paralell` (or any unknown value) is a run failure
  naming the file, not a silent default.
- Interleaved output never corrupts a result: each test's stdout is read from
  its own pipe and parsed independently; the human lines print whole.

## Documentation

When built, update `docs/testing.md`: the header-format section gains the
`concurrency` key (with the fail-safe default called out), the "What each tier
costs" section is re-measured, and the runner section documents `--workers`
and the exclusive-drain behavior. The declaration belongs in the same table
that documents `tier` and `env` — it is the same kind of promise.
