# Test Gate Flakiness — Vault Suites (investigation)

**Status:** open, unassigned. Written 2026-07-18 during the generation-2 tester
conversion, which is where the symptom surfaced.

## TL;DR

The `db` tier does not produce the same result twice. Three consecutive full runs
failed on three *different* vault suites, and every one of those suites passes
when run alone. The gate is therefore not currently trustworthy: a red `db` run
tells you nothing about whether the code under test is broken, and — worse — a
green one does not clear the suites that happened not to trip that round.

This is **not** a regression from recent work. It reproduces on a clean tree.

## Symptom

Full `php tests/run.php db`, same tree, no edits between runs:

| Run | Result | Failing suite(s) |
|-----|--------|------------------|
| 1 | FAIL | 4 tests / 5 checks (set not captured) |
| 2 | FAIL | `vault_wrappings_floor` 10/17 (7 checks) |
| 3 | FAIL | `vault_client_custody` 27/30 (3 checks) |
| 4 (stashed tree, see below) | FAIL | `vault_client_custody` 27/30 + `vault_ceremonies` 23/27 |

In isolation, each passes cleanly:

```
php tests/run.php db --filter=vault_wrappings   → 17/17 PASS
```

The failing checks inside `vault_wrappings_floor` are the substantive ones —
"refuses to strip the last passkey with no recovery codes", "a consumed code no
longer counts toward the floor", "soft-deleted wrappings do not count" — i.e.
the suite behaves as though it is reading another test's wrapping rows, not as
though the floor logic is broken.

## Attribution — already ruled out

The flakiness was found while converting the store testers, so the first
question was whether that work caused it. It did not:

- `git stash push --include-untracked` (removing every uncommitted change), then
  a full `db` run → still FAIL, 2 tests / 7 checks, on vault suites.
- Nothing in the store conversion touches vault code, vault tables, or
  `tests/vault/`.

So this predates the conversion and lives in the vault suites' interaction with
each other or with shared state.

## What is already known

- **The runner is sequential.** `tests/run.php` uses `proc_open()` immediately
  followed by `proc_close()`, which blocks until the child exits. Tests do not
  overlap, so this is not a parallel-execution race *between* suites. Ordering
  and leftover state are still in play.
- **One vault suite is internally concurrent.** `vault_recovery_concurrency_test.php`
  deliberately spawns 8 real PHP worker processes with their own DB connections
  and releases them on a shared wall-clock barrier. It is the obvious candidate
  for leaving residue (rows, connections, advisory locks) behind for whichever
  vault suite the runner happens to schedule next.
- **Connection headroom is thin.** PostgreSQL `max_connections` is 100, and a
  separate measurement during this session showed `MultiModelTester` exhausting
  the pool outright ("sorry, too many clients already"). A suite that leaks
  connections degrades later suites rather than failing itself.
- **There are 10 declared vault suites**, so cross-suite fixture collision has a
  wide surface: several create vaults, wrappings, and recovery codes for users
  that may not be uniquely namespaced per run.

## Hypotheses, most likely first

1. **Fixture bleed between vault suites.** Two or more suites create wrappings
   for users that collide (shared email/user, or a floor count computed over
   rows another suite left). Predicts: failures track *run order*, and forcing a
   fixed order reproduces deterministically.
2. **`vault_recovery_concurrency` residue.** Its 8 workers leave rows, held
   connections, or advisory locks that the next vault suite reads. Predicts:
   running it immediately before each other vault suite reproduces; running the
   others without it never fails.
3. **Connection-pool exhaustion.** Accumulated leaked connections make a later
   suite fail on connect rather than on logic. Predicts: `pg_stat_activity`
   count climbs monotonically across a full run, and failures cluster at the end.
4. **APCu / in-process cache carryover.** The gate runs with `apc.enable_cli=1`
   (added under T8 so `vault_unlock_window` can exercise unlock windows). A
   cached unlock window or key that outlives its suite could satisfy or break a
   later assertion. Predicts: failures vanish with APCu disabled.

## Method

1. **Make it deterministic before fixing anything.** Add a `--seed`/`--order`
   option (or just log the executed order) so a failing sequence can be replayed
   exactly. Without a reproducer this cannot be verified fixed.
2. **Bisect by order.** Run the 10 vault suites in isolation, then pairwise
   (A then B) across the matrix, recording which ordered pairs fail. That
   identifies the polluter and the victim directly, and distinguishes
   hypothesis 1 from 2.
3. **Instrument connections.** Sample `SELECT count(*) FROM pg_stat_activity`
   between suites during a full run; a monotonic climb confirms 3.
4. **Test the APCu hypothesis cheaply** by running the vault subset with
   `apc.enable_cli=0` and seeing whether the flake disappears (note this will
   legitimately skip `vault_unlock_window` checks).
5. **Fix at the cause, not the symptom.** Per the standing no-band-aid rule: if
   suites collide on fixtures, namespace the fixtures per run (the pattern the
   mailbox and store suites already use — a `$run_id` in every generated
   identifier); do not add retries or sleeps to paper over the collision.

## Acceptance

- A named, replayable ordering that reproduces the failure on demand, and the
  same ordering passing after the fix.
- 20 consecutive full `db` runs green on an unchanged tree.
- No suite leaves rows, connections, or advisory locks behind: a
  before/after count of vault rows and `pg_stat_activity` entries across a full
  run is unchanged.
- Whatever is found is written up in `specs/test_estate_audit.md` alongside the
  other estate findings.

## Related

- `specs/test_estate_audit.md` — the parent audit. Its T8 (APCu in the gate),
  T17 (vault recovery concurrency) and T20 (teardown) entries are the relevant
  history; T17's own load-flakiness was fixed 2026-07-18 by moving the recovery
  KEK derivation inside the per-wrapping try/catch, which is a *different* bug
  from this one.
- Adjacent, found the same day and also unresolved: the `test-db` tier is red
  independently of any of this — `models_crud` reports 66 passed / 85 failed of
  151 model classes on a clean tree. That is its own investigation, not part of
  this one, but it means neither `db` nor `test-db` is currently a gate you can
  trust. Only `safe` (37/37) is reliable today.
