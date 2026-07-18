# Model CRUD Suite Repair (`models_crud`, tier `test-db`)

**Status:** COMPLETE 2026-07-18 — 151/151 green, two consecutive full runs,
residue census unchanged (zero created-parent rows survive a run). Implemented
as specified with one measured deviation: undeclared-but-resolvable references
also get **created** parents (not selected existing rows), because selection
collided with real data — existing rows' unique combinations are already taken
(user #1 already had a StripeCustomer/DriveUsage row, was already a
conversation participant and event registrant), and a child whose delete
cascades into its parent reached real rows (AiMessageAttachment → File). Fresh
parents per run are what makes the outcome independent of local ids.
Additional root causes found beyond the FK finding: `get_nullable_fields()`
honored only `'required'`, so `test_null_value()` force-nulled every
`is_nullable => false` column — that one bug was the entire 22-model NOT NULL
bucket; the generator had no char(n)/time/json(b) support (FileShareLink,
FileUpload, ScheduleWindow, ManagementJob); and models with cross-field
business rules got a declarative escape hatch (`static $test_fixture`, see
docs/testing.md) — AiMemory is the first consumer.

Written 2026-07-18 during the generation-2 tester conversion, which is where the
suite's true state surfaced.

## TL;DR

`models_crud` drives `ModelTester` over all 151 data models and was reporting
**66 passed / 85 failed** on a clean tree — the `test-db` tier has not been a
usable gate. Two platform-level causes have been fixed, taking it to
**115 passed / 36 failed**. What remains is real, and one part of it is a
correctness problem rather than a test problem: **the generator invents foreign
key values by hashing the field name.** That only ever worked because the
database had no foreign key constraints. Now that T32 has materialized them,
those inserts fail — and the ones that still pass do so by luck, because the
hash happened to land on a real row in *this* database.

## Already fixed — do not redo

1. **Connection leak (cleared ~50 checks).** `DbConnector::execute_query()`
   pushed the `PDOStatement` into `query_history`. A statement holds a reference
   to the connection that prepared it, so every connection ever opened stayed
   alive; the suite reached 80 connections in 12 seconds and died on
   `FATAL: sorry, too many clients already`. It now records the query string
   (the only consumer is error context via `print_r`, which read the string
   anyway), capped at 50. `close_test_mode()` also releases its handle instead
   of only flipping a flag. Connections now hold steady at 1–3.

2. **Generator ignored NOT NULL.** Two field-spec keys express the same intent
   and have drifted across the estate: `'required' => true` drives model-level
   validation *and* the test generator, while `'is_nullable' => false` drives
   the database constraint. Models declaring only the NOT NULL half had those
   fields left unset — and for varchars were handed `''`, which `SystemBase`
   stores as NULL. A shared `ModelTester::field_requires_value()` now honors
   both, used by the data generator and the varchar generator. Serial and
   defaulted columns are still left to the database.

**Deliberately not done:** adding `'required' => true` to the ~30 data classes
that declare only `is_nullable`. `required` makes `save()` **throw**, so that
would change runtime behavior on every existing save path for those models.
Fixing the generator was the correct layer. Whether those models should *also*
validate at the model layer is a separate product decision, and if taken it
should be per-model with verification, not a sweep.

## Current state: 35 failures

| count | bucket |
|-------|--------|
| 22 | NOT NULL violation — a *second* required field the generator still leaves unset |
| 3  | **foreign key violation** — generator invents a reference (see below) |
| 2  | value too long — generator exceeds the column width |
| 8  | assorted, each needing its own look |

The 8 assorted: `EntityPhoto` (specified file does not exist), `ScheduleWindow`
(string length), `UserEncryptionVault` (composite unique on
`uev_usr_user_id, uev_scope`), `InboundEmailFilter`, `InboundEmailMessage`,
`MailboxFleetSlot`, `ManagementJob` (15 sub-checks), `AiMemory`
(`Invalid memory scope 'a'` — the generator's single-char varchar value is
rejected by model validation).

## The foreign key finding

`ModelTester::generate_integer_value()` treats any field containing `_id` as a
foreign key and produces a value from `crc32($field) % 10000` plus an offset. It
never checks that the target row exists — it cannot, because it has no notion of
what table the field points at.

Measured against the six constraints T32 materialized:

| field | generator emits | target exists |
|---|---|---|
| `uev_usr_user_id` | 3065 | yes |
| `pkc_usr_user_id` | 267 | yes |
| `vlk_usr_user_id` | 438 | yes |
| `vle_usr_user_id` | 5238 | **no** |
| `imi_usr_user_id` | 6859 | **no** |
| `uew_uev_user_encryption_vault_id` | 8556 | **no** |

Those three are exactly the three FK violations in the failure list
(`VaultEntry`, `InboundMailboxSearchIndex`, `UserEncryptionWrapping`). The other
three pass only because the hash happened to hit a live row in the current dev
database — a fresh install or production has different rows, so the outcome is
**environment-dependent and nondeterministic**. Treat the three passes as
accidental, not as coverage.

This matters beyond the suite: a generic CRUD tester that cannot create a valid
parent row cannot test any model with a required parent, which is most of the
interesting ones.

### The fix

Make the generator FK-aware, using the same declaration T32 made authoritative:

- Read the `'foreign_key'` field spec (target table + column) rather than
  pattern-matching on `_id`. That key is now the single source of truth for
  referential intent, so the generator and `DatabaseUpdater` agree by
  construction.
- For a field with a declared foreign key, **create a real parent row** (via the
  target model, so its own required fields and validation are satisfied) and use
  its key. Register it for teardown so the parent goes when the child does.
- Where no `'foreign_key'` is declared but the field matches `_id`, prefer
  selecting an existing row's id over inventing one, and skip the field when the
  table is empty rather than fabricating a dangling reference.
- Undeclared-but-real relationships will surface as the estate's
  `'foreign_key'` coverage grows; the generator should degrade safely
  (skip/select) rather than invent, so it never manufactures the orphans T32
  just spent a migration cleaning up.

## Method for the rest

1. **Fix the FK bucket first.** It is one change and it unblocks the vault and
   mailbox models, which are also in the NOT NULL bucket — several of the 22 are
   likely masked behind it.
2. **Re-measure before touching the NOT NULL bucket.** Each fix in this suite
   has shifted models to their *next* missing field rather than clearing them,
   so the bucket counts move underneath you. Re-run after every change.
3. **Triage the 22 individually.** For each, decide whether the generator should
   populate the field or the model's spec is wrong (a column marked NOT NULL in
   the database that the model does not declare at all is schema/model drift and
   should be reconciled, not worked around).
4. **The 8 assorted are separate bugs**, several in the generator's value
   choices (single-char varchars rejected by model validation, over-long
   strings). Fix the generator where the value is unreasonable; fix the model
   where the constraint is real.

## Acceptance

- `php tests/run.php test-db --filter=models_crud` green, 151/151.
- No generated foreign key references a nonexistent row: the suite creates its
  parents and removes them. A residue census before and after a full run is
  unchanged (the standing `tests/schema/referential_integrity_test.php` gate
  will red the next run if it is not).
- Two consecutive full runs green, and green on a freshly resynced test database
  — proving no result depends on which ids happen to exist locally.
- The `test-db` tier goes into the routine gate rotation; today only `safe` is
  trustworthy.

## Related

- `specs/implemented/test_gate_flakiness.md` — T32. Materialized the six FK
  constraints this spec's central finding depends on, made sequences
  forward-only, and added the standing referential-integrity gate.
- `specs/test_estate_audit.md` — parent audit. **T30** (the entire Multi
  collection surface is untested) is blocked behind this: `models_test.php`
  hard-sets `SINGLE_TESTS_ONLY=true` / `TEST_MULTI=false`, so
  `MultiModelTester` (37KB) never runs from the gate. A measured probe with
  `MULTI_TESTS_ONLY` showed the Multi engine itself works (9 pass / 0 fail /
  3 skip on a 12-model sample), so enabling it is wiring plus whatever the full
  sweep then surfaces — after this suite is green, not before.
- **Test database parity matters.** The test database is a separate copy and
  does not receive `update_database`; it was rebuilt from live on 2026-07-18 to
  pick up the six FK constraints. A test DB that lags live validates against a
  schema production does not have. Resync from `/admin/admin_test_database`
  after any schema change.
