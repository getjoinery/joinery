# Harness Fixture Sweep

**Status: UNBUILT — proposed 2026-07-25.** Design only; nothing implemented.

## What this is

Test fixtures leak into the dev database and stay there. Today's cleanup lives
entirely inside the process that created the fixtures, so any death that skips
PHP's shutdown functions strands every row that run created. This makes the
*next* run responsible for the *previous* run's leftovers, so the estate heals
itself instead of accumulating debris that a human has to notice and delete.

Scope is the shared harness (`tests/lib/harness.php`) and the guard that
currently reports the symptom (`tests/schema/referential_integrity_test.php`).
No test file changes: fixtures are already registered through the harness, and
this changes what registration *does*, not how tests call it.

## Why

db-tier tests write to the live dev database by design. `harness_register_row()`
(`tests/lib/harness.php:331`) handles cleanup by deferring a closure:

```php
function harness_register_row($table, $pkey_column, $id) {
    harness_defer(function () use ($table, $pkey_column, $id) { ... DELETE ... });
}
```

Every registration — rows, API keys, users — is an in-memory closure run by
`harness_teardown_data()` from `harness_finish()` or the shutdown reporter. The
list exists only in that process's memory. When the process dies without running
shutdown functions, the list dies with it and every row it described is orphaned.

That happens for reasons no in-process mechanism can cover:

- `SIGKILL`, including the runner's own `timeout -k 5s` escalation when a test
  overshoots its grace window
- an OOM kill
- `Ctrl-C` (SIGINT), which today has no handler at all — only SIGTERM does
  (`tests/lib/harness.php:168-170`)

Two observed instances, both from single interrupted runs:

- `harnesstest_luowner_cf55b29c@` and `harnesstest_luother_cf55b29c@`
  (`usr_users` 16260/16261), from `plugins/mailbox/tests/lowering_unseal_test.php:102,107`.
  Both carry the same run token and a NULL `usr_delete_time` — teardown never
  started, rather than started and failed.
- `HarnessTest Node <hex>` rows from
  `plugins/server_manager/tests/job_command_builder_test.php`, which surfaced in
  the Server Manager dashboard as phantom nodes.

The second case is why a per-table fix is the wrong shape. `referential_integrity`
greps for `harnesstest_%@getjoinery.com`, which catches users and nothing else;
the managed-node rows were invisible to it and only came to light because someone
looked at an admin page.

## The approach

Persist what registration currently only remembers, and sweep at boot.

**Registry.** A table the test estate owns and creates on demand, holding one
row per created fixture:

| column | purpose |
| --- | --- |
| `hfx_id` | creation order; sweep deletes in reverse |
| `hfx_run_token` | which run created it |
| `hfx_pid` | creating process, for liveness refinement |
| `hfx_table` | table the fixture lives in |
| `hfx_pkey_column` | its primary-key column |
| `hfx_pkey_value` | its primary-key value |
| `hfx_created_time` | UTC, for the age threshold |

Created with `CREATE TABLE IF NOT EXISTS` from the harness, not from a data
class. It is test-estate bookkeeping, not platform schema: it must not appear in
`update_database`, must not ship to a production install, and must not be picked
up by the model sweeps. It follows the active connection, so the test-db tier
gets its own registry for free.

**Registration writes a row.** `harness_register_row()`, `harness_register_key_id()`
and `harness_register_user()` keep deferring their existing closure *and* insert
a registry row. The in-process path stays the fast, model-aware one — notably
`harness_register_user()`'s `permanent_delete()` with its soft-delete fallback.
The registry is the backstop, not the replacement.

**Teardown clears its own rows.** A fixture torn down normally deletes its
registry row in the same closure, so a clean run leaves the registry empty and
the sweeper has nothing to consider.

**Sweep at boot.** `harness_boot()` deletes fixtures belonging to any run token
other than the current one whose `hfx_created_time` is older than the staleness
threshold, in descending `hfx_id` order, then removes their registry rows.

Reverse-id order is what makes raw SQL sufficient: fixtures are created parents
first, so deleting newest-first removes children before parents and the delete
lands without relying on cascade rules. Where the database does cascade, it just
works — deleting `usr_users` 16260 takes its `uev_user_encryption_vaults` row
with it, because that FK is `ON DELETE CASCADE`.

**A failed sweep must not break the run.** If a raw delete fails — an FK the
sweep order didn't anticipate, a table dropped since — log it, leave the registry
row in place for the next boot to retry, and continue. Boot never aborts because
a previous run's mess is stubborn.

**Catch SIGINT too.** Handling it alongside SIGTERM turns the most common human
interrupt into a clean teardown. It covers nothing the sweep doesn't already
cover; it just stops the sweep from being needed so often.

**The guard becomes a real guard.** `referential_integrity` keeps its
`harnesstest_%` check unchanged. After this it can only fail if the sweeper
itself is broken or something created a fixture without registering it — both
worth being loud about. It stops being a recurring chore and goes back to being
a signal.

## What this deliberately does not do

**Transaction-per-test with rollback.** The tidiest answer in the abstract, and
it would survive SIGKILL for free, since a dropped connection rolls back
uncommitted work automatically. It does not work here: db- and live-tier tests
shell out to PHP CLI scripts and drive the site over HTTP, and those use their
own connections. They would see none of the uncommitted fixture data, and a
large part of the estate would start failing for reasons unrelated to what it
tests.

**Close the registration gap.** A run killed between creating a row and
registering it still leaks, because the primary key does not exist until the row
does. The window is microseconds and only transactions would close it. The boot
sweep plus the `referential_integrity` guard is the accepted trade.

**Hide fixtures from admin UIs.** Fixture rows are still visible in the Server
Manager dashboard while a db-tier run is in flight. That is a separate question
about marking test rows so pages can filter them, not about leaks, and it is out
of scope here.

## Phases

1. **Registry.** Table creation, the insert inside the three registration
   helpers, and registry-row removal on normal teardown.
2. **Sweep.** The boot sweep with the age threshold, reverse-id ordering, and
   the failure-tolerant delete loop.
3. **SIGINT.** Extend the existing signal handling.
4. **Docs.** `docs/testing.md` gains the fixture lifecycle: what registration
   records, when the sweep runs, what the staleness threshold means, and what a
   `referential_integrity` failure now indicates.

## Acceptance

1. A db-tier test killed with `SIGKILL` mid-run leaves rows behind; the next
   `harness_boot()` removes them, and `referential_integrity` passes without
   anyone touching the database by hand.
2. The sweep never deletes a row belonging to the current run token.
3. The sweep never deletes another token's rows that are younger than the
   staleness threshold, so a dashboard run overlapping a CLI run is safe.
4. A registered parent/child pair is swept without an FK error, by ordering
   alone.
5. A delete the sweep cannot perform is logged, leaves its registry row for the
   next boot, and does not abort `harness_boot()`.
6. `Ctrl-C` during a db-tier test runs normal teardown: nothing registered, and
   nothing for the next run to sweep.
7. A row inserted without registration is still caught by `referential_integrity`
   — the guard has not been quietly disarmed by the thing meant to satisfy it.
8. A clean full db-tier run leaves the registry empty.

## Open decisions

- **Staleness threshold.** 30 minutes is the proposed default: comfortably
  longer than the db tier's ~5.5 minutes, short enough that leftovers do not
  survive a working session. Runs are serial — the parallel runner was rejected
  — so this only guards against a dashboard run overlapping a CLI run.
- **PID liveness.** `hfx_pid` allows sweeping a dead run's rows immediately
  instead of waiting out the threshold, but only when the sweeper runs on the
  same host that created them. Worth including only if the threshold alone
  proves too slow in practice.
- **Registry table name.** `hfx_harness_fixtures` follows the platform's prefix
  convention, which aids readability but may invite the assumption that it is
  platform schema. An unprefixed name would signal "not ours" more clearly.
