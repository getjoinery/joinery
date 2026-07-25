# Harness Fixture Leaks

**Status: BUILT 2026-07-25** (scoped down the same day). Both changes are in.
The automated sweep this started as was rejected on the evidence; see
[Considered and rejected](#considered-and-rejected).

## What this is

Test fixtures occasionally survive a run and sit in the dev database. Two
changes: stop the commonest cause, and make a leak that does happen visible
straight away. **Nothing here deletes anything automatically.**

## How a leak happens

Not the way it looks. A *failing* test cleans up perfectly well — cleanup is a
list of closures held in the test process, drained by `harness_finish()` on a
clean end and by `register_shutdown_function('harness_shutdown_report')` on a
crash. An assertion failure, an uncaught exception and a fatal error all still
run shutdown functions, so the rows go away.

A leak needs a process that dies **without reaching its shutdown functions**,
because the cleanup list exists only in that process's memory and is never
written anywhere. Three ways:

- **`Ctrl-C`.** No SIGINT handler exists, so PHP takes the default disposition
  and terminates immediately. The likeliest cause of the one traceable incident.
- **The runner's timeout escalation.** `tests/run.php:270` wraps each test in
  `timeout -k 5s <N>s` — SIGTERM at N seconds, SIGKILL five later. The harness
  converts SIGTERM to `exit(1)` so teardown normally makes it inside the grace
  window, but a wedged test can be killed first.
- **`kill -9` or the OOM killer.** Uncatchable.

The observed evidence: `harnesstest_luowner_cf55b29c@` and
`harnesstest_luother_cf55b29c@` (`usr_users` 16260/16261) from
`plugins/mailbox/tests/lowering_unseal_test.php:102,107`. Same run token, so one
process; created in the same second; `usr_delete_time` NULL. That NULL is the
tell — `harness_register_user()` falls back to stamping a soft delete when a
permanent delete is refused, so a NULL means teardown never ran at all.

## What to build

**1. Catch SIGINT.** Extend the existing SIGTERM handling in `harness_boot()`
(`tests/lib/harness.php:168-176`) to SIGINT, converting the interrupt into
`exit(1)` so the shutdown reporter runs teardown normally. About ten lines beside
code that already does this for SIGTERM. It removes the most likely cause
outright.

**2. Widen the guard.** `tests/schema/referential_integrity_test.php` checks for
leftover `harnesstest_%` users and `vault-test-%` passkey credentials. Add checks
for the other families that name themselves — `HarnessTest %` in `evt_events`,
`svy_surveys`, `grp_groups`, `pro_products`, `bkt_booking_types`,
`mgn_managed_nodes` — as further `LIKE` checks in the same test. No catalogue
abstraction, no new file.

This is the piece that pays. Cleanup was never expensive: it is one `DELETE`, or
`permanent_delete()` for a user. What cost time was *diagnosis* — the
`HarnessTest Node` rows from `plugins/server_manager/tests/job_command_builder_test.php`
were invisible to every check and only surfaced because someone noticed phantom
nodes in the Server Manager dashboard. A guard that names the leak and the test
that made it turns an investigation into a one-line fix.

## Acceptance

1. `Ctrl-C` during a db-tier test runs normal teardown and leaves no fixtures.
2. A `HarnessTest`-named row left in any of the listed tables fails
   `referential_integrity` with the table and the row named.
3. The guard still passes on a clean estate, and still catches the `usr_users`
   and `pkc_passkey_credentials` cases it caught before.

## Considered and rejected

Recorded so the analysis is not redone from scratch. If leaks ever become
recurring rather than occasional, this is the groundwork.

### An automated boot sweep

The original design: recognise leaked fixtures by name at `harness_boot()`,
delete them through the model, gated on a 30-minute age threshold, tier and the
`debug` setting, with a 50-row sanity cap.

**Rejected on volume.** Total surviving debris across the whole history of the
test estate, measured 2026-07-25:

| table | leaked rows |
| --- | --- |
| `usr_users` | 2 |
| `evt_events`, `svy_surveys`, `mgn_managed_nodes`, `grp_groups`, `pro_products`, `bkt_booking_types`, `cal_entries`, `qst_questions` | 0 |

Two rows, from one incident. Earlier leaks were presumably cleaned by hand and
left no trace — the phantom nodes prove that happened at least once — so call it
two noticed incidents in the estate's lifetime, each resolved in minutes. The
sweep would have needed a fixture catalogue, per-pattern age columns, tier and
`debug` gating, a sanity cap, a failure-tolerant delete loop, a naming convention
rolled out to further fixture families, and registration-time warnings to keep it
honest. That is a large, permanently-maintained mechanism, holding delete
authority over the dev database, to automate something that has happened twice.

### A fixture registry with raw deletes

Before the name-based sweep, a `harness_fixtures` table recording every created
row, swept at boot with raw SQL and PID/host liveness.

**Rejected as actively dangerous.** For users — the most common fixture and the
only ones observed leaking — a raw delete is not equivalent to what teardown does:

| | count |
| --- | --- |
| tables carrying a `usr_user_id` column | 71 |
| FK constraints referencing `usr_users` | 5 (all `ON DELETE CASCADE`) |
| rows in `del_deletion_rules` for `usr_users` | 70 |

`harness_register_user()` calls `permanent_delete()`, which walks those 70 rules.
A raw `DELETE FROM usr_users WHERE usr_user_id = ?` succeeds — only 5 constraints
can object — and silently orphans references across up to 66 tables. The guard
greps for `harnesstest_%` *users*, so once the row is gone it reports clean while
the wreckage remains. It would convert a visible, inert leak into invisible
corruption.

It also required excluding the table from `utils/create_install_sql.php`, whose
whole-database `pg_dump --schema-only` would otherwise ship it into every
generated install.

### Transaction-per-test with rollback

The tidiest answer in the abstract, and it would survive `SIGKILL` for free.
**Does not work here:** db- and live-tier tests shell out to PHP CLI scripts and
drive the site over HTTP on their own connections. They would see none of the
uncommitted fixture data, and much of the estate would fail for reasons unrelated
to what it tests.

## Findings worth keeping

**Most fixtures need no name of their own.** Deleting through the model runs the
deletion rules, so a parent takes its children with it. Of the 30 tables tests
register rows in, 25 are reachable that way and most chains bottom out at
`usr_users`. Sweeping one fixture user would remove its groups and group members,
orders and order items, events and registrants, conversations and messages,
files and attachments, subscription tiers, products, passkey credentials, vault
and memories. API keys too — `apk_api_keys` is user-rooted.

**Reachability is not only `del_deletion_rules`.** A polymorphic owner cannot be
expressed as a deletion rule, so the calendar tables are cleaned by a hand-written
path: `User::permanent_delete()` calls `CalendarSubject::user($id)->purge()`
(`data/users_class.php:1181`), which permanently deletes that subject's
`sch_schedules` and `cal_entries`. **Any analysis built by querying
`del_deletion_rules` alone will wrongly conclude those two tables are orphaned.**
This spec did exactly that before the code was read.

`purge()` is wired only for the `user` subject type; `CalendarSubject` also
declares `resource`, `team` and `venue`. No test creates one today. If one ever
does, the fix is to call `purge()` from that owner's deletion path — not to invent
a label column.

**A leaked schedule is nearly harmless.** Lookups go through
`Schedule::for_subject()`, keyed on `(subject_type, subject_id)`, and there is no
global scan. Once the subject is gone nothing queries the row. The only lasting
effect is the partial unique index on `(sch_subject_type, sch_subject_id)` holding
that subject's slot — harmless while primary keys are never reused, and an
orphan-re-attachment hazard if one ever is.
