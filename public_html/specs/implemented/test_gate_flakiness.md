# Test Gate Flakiness — Vault Suites (root-caused; fixed)

**Status:** IMPLEMENTED 2026-07-18, same day as the investigation. All five fix
parts landed and are verified below (see "Implementation record" at the end).
Originally written as an open investigation during the generation-2 tester
conversion.

## TL;DR

The `db` tier failed non-deterministically on a rotating cast of vault suites.
This is **not** a vault-logic bug, not test ordering, not APCu, not connection
exhaustion, and not fixture-name collision. Two platform defects compound:

1. **Sequence rewind.** `utils/update_database.php` Step 5 ("SEQUENCE SYNC",
   line ~533) runs `setval(seq, MAX(pkey))` on every serial table. Test
   teardown deletes the newest rows, so MAX collapses and the next
   `update_database` run moves sequences **backwards**. Primary keys get
   reallocated.
2. **Referential integrity is not enforced anywhere at the DB level.** The
   database contains **zero** foreign-key constraints. The
   `'foreign_key' => [...]` field-spec key (declared in 5 data classes,
   including both vault tables with `on_delete => CASCADE`) is materialized by
   nothing — `DatabaseUpdater` has no FK support. Meanwhile the harness's
   `harness_register_row()` teardown is a raw `DELETE`, which bypasses the
   PHP-level deletion doctrine. Deleting a test vault therefore **orphans all
   of its wrapping rows**, silently, every run. `tests/lib/vault_fixtures.php`
   even documented the false assumption: "uew rows cascade with the vault row
   (FK ON DELETE CASCADE)".

Compound effect: orphaned `uew_user_encryption_wrappings` rows accumulate
(1,714 rows across 105 dead vault IDs, range 262–553, as of 2026-07-18) while
the vault sequence had been rewound to 270. Every `db` run allocates fresh
vaults straight into the orphan range; a new vault **inherits whichever orphan
wrappings share its recycled ID**. Vault-scoped queries (the floor logic,
status counts, used-code counts) then see another run's rows, and the
substantive checks fail. Which suite fails on a given run depends only on
which suite happens to draw a contaminated ID — hence three consecutive runs
failing on three different suites, and every suite passing in isolation.

This is worse than a test problem. PK reuse plus orphaned children means a
*production* delete-then-resync could attach a previous owner's rows — for
these tables, another identity's **vault wrappings** — to a newly created row.
That is a cryptographic-identity hazard and the reason the fix is at the
platform layer, not in the tests.

## Proof

Census on the dev DB, 2026-07-18 (after three failing runs, clean tree):

```sql
-- 0 leftover harness users, but:
SELECT count(*) FROM uew_user_encryption_wrappings;              -- 1714
SELECT count(DISTINCT uew_uev_user_encryption_vault_id)
  FROM uew_user_encryption_wrappings w
  WHERE NOT EXISTS (SELECT 1 FROM uev_user_encryption_vaults v
    WHERE v.uev_user_encryption_vault_id = w.uew_uev_user_encryption_vault_id);
                                                                 -- 105 dead vault IDs, range 262–553
SELECT last_value FROM uev_user_encryption_vaults_uev_user_encryption_vault_id_seq;
                                                                 -- 270  ← rewound below historical max 553
SELECT count(*) FROM information_schema.table_constraints
  WHERE constraint_type = 'FOREIGN KEY';                         -- 0, entire database
```

Controlled runs, same tree, no edits:

- Run over mostly-clean IDs 271–279 → `db --filter=vault` **190/190 PASS**.
- Next run over contaminated IDs 280–288 (orphan census predicted: ID 280
  carries 5 live unused recovery codes + a live passkey, 281 carries 20
  recovery codes, 283 carries 40 rows…) → **FAIL**, `vault_client_custody`
  27/30 with pure count-inflation checks ("status counts the passkey
  wrapping", "status counts unused recovery wrappings", cross-scope veto) —
  the exact signature of the original failure table.
- The original observations decode the same way: `vault_ceremonies` counting
  `{"passkey":2,"recovery":12}` where it created 1+7, and
  `vault_recovery_concurrency` counting `used: 2` where exactly one worker
  consumed a code — inherited orphan rows, not logic bugs and not a race.

An independent code audit of the query paths confirms the bleed channel is ID
reuse and nothing else: every floor/count query is scoped to `vault_id`
(`VaultUnlock::assertWrappingDeleteSafe` at includes/VaultUnlock.php:432-468,
`UserEncryptionWrapping::liveGenerations`, every `VaultCeremonies` counting
path), `MultiUserEncryptionWrapping::getMultiResults` has no
accepted-but-ignored filter key, liveness is a `delete_time IS NULL` check
(never a time comparison), and none of these paths cache (no statics, no
APCu). The repo even contains a smoking gun:
`tests/vault/vault_recovery_concurrency_test.php:55-66` explicitly deletes its
wrappings with the comment "wrappings are **not cascaded** with it — remove
them explicitly", directly contradicting the fixture comment — that one suite
compensated for the missing cascade; every other suite trusted it.

A secondary cross-run failure mode rides along: `make_user()` emails are fixed
per suffix (`harnesstest_vaultwrap@…`), `User::GetByEmail` uniqueness matches
soft-deleted users, and `harness_register_user`'s fallback path soft-deletes a
user (stranding its vault rows) when `permanent_delete()` fails. A stranded
same-suffix user makes the next run's `make_user()` throw "already been used"
— the abort-shaped failure of the original Run 1 (4 tests / 5 checks).

Original hypotheses, resolved:

| Hypothesis | Verdict |
|---|---|
| 1. Fixture bleed between suites | **Confirmed — but via PK reuse**, not shared emails/users. Fixture naming was never the vector. |
| 2. `vault_recovery_concurrency` residue | No. Its orphans are the same class as every other suite's; it is not special. Its `used: 2` failure was inherited orphans. |
| 3. Connection-pool exhaustion | No. Suites run in sequential subprocesses; connections close at exit. Never reproduced. |
| 4. APCu carryover | Impossible. CLI APCu is per-process; each test is its own subprocess. |

## The fix

Five parts. Parts 1–2 are the platform-level causes; 3 is the one-time estate
repair; 4–5 keep it fixed. No part is a retry, sleep, or test-side workaround.

### 1. Sequences are forward-only — everywhere, permanently

Invariant: **a primary key, once allocated, is never reallocated.** No code
path may move a sequence backwards.

- Replace `update_database.php` Step 5's `setval(seq, COALESCE(MAX(pkey),1))`
  with a forward-only sync: advance the sequence only when it is *behind*
  `MAX(pkey)` (the legitimate case after a data import with explicit IDs);
  otherwise leave it untouched.
- Extract one shared helper (natural home: `DatabaseUpdater`) and route every
  sequence-adjusting path through it:
  - `utils/update_database.php` Step 5 — the rewinder; must switch.
  - `utils/fix_duplicate_keys.php` (~line 438) — sets the sequence to its
    computed next-id, which can also rewind; must switch.
  - `utils/fix_sequences.php` — already forward-only (`current <= max` guard);
    converge it on the shared helper so the logic exists once.
  - `utils/create_install_sql.php` — writes `setval` into install SQL for a
    fresh DB; correct as-is but should use the helper's SQL form for
    consistency.

### 2. Materialize declared foreign keys as real DB constraints

The `'foreign_key'` field-spec key stops being decorative. Two integrity
layers, each doing what only it can do:

- **PHP deletion doctrine** (`$foreign_key_actions`, soft-delete cascades)
  remains the authority for business-logic deletion — it understands soft
  deletes, sentinel values, and per-model `permanent_delete()` logic.
- **DB constraints** become the invariant of last resort — the only mechanism
  that holds under raw SQL, a crashed process, or a SIGKILLed test.

Implementation:

- `DatabaseUpdater` learns the `'foreign_key'` spec key:
  - **Create path:** new tables are created with their declared constraints
    (fresh installs get them from day one — no new config, per zero-config
    doctrine).
  - **Sync path:** existing tables get missing constraints added
    (`ALTER TABLE … ADD CONSTRAINT … FOREIGN KEY … REFERENCES … ON DELETE …`).
    An existing constraint whose `ON DELETE` action differs from the
    declaration is dropped and recreated to match. The declaration in the data
    class is the single source of truth.
  - **Orphans block, loudly.** If constraint creation fails because orphan
    rows exist, the sync reports the table, the FK, and the orphan count as an
    error. Never skip silently — a silently skipped constraint is exactly the
    lie that caused this bug.
- `PluginManager::sync()` applies the same treatment to plugin tables (three
  of the five current declarations are plugin-owned: `vault_keyring`,
  `vault_entries`, mailbox `inbound_mailbox_search_index`).
- **Declaration policy, decided once:** a hard ownership edge — a child row
  that is meaningless or dangerous without its parent — declares
  `'foreign_key'` with `on_delete => CASCADE` (or `SET NULL` for optional
  refs). The five existing declarations become real. As part of this work,
  audit the security-sensitive families (vault, wrappings, passkeys, keyring,
  sealed-vault consumers) and add declarations where the same property holds.
  Broad estate-wide adoption for ordinary tables is *not* required — the PHP
  doctrine already governs them — but any table where orphan re-attachment
  would be a correctness or security hazard must carry the constraint.
- Ordering compatibility: PHP sweeps delete children before parents, so a DB
  `CASCADE` is a no-op behind them — the layers cannot fight.

### 3. One-time estate cleanup (migration)

A migration (data-only, per migration rules) that runs before the FK sync
step can succeed:

1. For every declared FK relationship, delete child rows whose parent no
   longer exists (the 1,714 orphaned wrappings, plus any orphans found under
   the other four declarations).
2. Delete stray test fixtures that predate the harness fixes: passkey rows
   `pkc_credential_id LIKE 'vault-test-%'` whose user is gone (16 as of
   2026-07-18), and legacy `vaulttest_%@getjoinery.com` fixture users and
   their vaults.
3. Do **not** touch sequences. After orphan cleanup, reuse of the already-
   burned ID range is harmless, and Part 1 prevents new rewinds.

Pre-launch, no production data is at risk (per the no-production-users
principle); the same migration ships to the managed nodes through the normal
deploy pipeline.

### 4. Harness and fixture correctness

- `tests/lib/vault_fixtures.php`: the "uew rows cascade" comment becomes true
  once Part 2 lands — reword it to state the constraint it now relies on.
  Until then it is a false statement of fact; fix it with the same change,
  never separately.
- `make_user()` in `tests/lib/harness.php`: append a per-process random token
  to the generated email (`harnesstest_{suffix}_{token}@getjoinery.com`) so a
  SIGKILLed run's leftover user can never collide with the next run — this
  also closes the secondary "already been used" abort mode (fixed email +
  soft-delete-blind `GetByEmail` + the soft-delete teardown fallback).
  Teardown by object reference is unaffected. `GetByEmail` matching
  soft-deleted users is intentional (a new signup must not claim a
  soft-deleted account's email) and stays.
- `vault_recovery_concurrency_test.php`: its hand-rolled compensating
  wrapping `DELETE` (lines 55-66) becomes redundant once the real cascade
  lands — remove it so the suite exercises the same teardown contract as
  every other suite. Its workers only `UPDATE` existing rows (verified), so
  no additional worker-side registration is needed.
- `harness_register_row()` stays a raw `DELETE` by design (last-resort row
  removal); with real constraints, deleting a registered parent now genuinely
  cascades.

### 5. Permanent guard: referential-integrity gate test

New `tests/schema/referential_integrity_test.php` — tier `safe` (read-only,
so it runs in every gate invocation), env `dev-only`:

- Every `'foreign_key'` declaration in every loaded data class (core and
  plugins) exists in `pg_constraint` with the declared `ON DELETE` action.
- Zero orphan child rows for every declared FK.
- Every serial sequence's `last_value` ≥ `MAX(pkey)` of its table.
- Zero leftover harness fixtures (`harnesstest_%` users, `vault-test-%`
  passkeys).

This converts "no suite leaves residue" from a one-time acceptance check into
a standing red/green signal: the next leak fails the very next `safe` run
with the table and count named, instead of surfacing weeks later as
unexplainable flakiness three suites away.

## Documentation

- `docs/deletion_system.md`: add the DB-constraint layer — the
  `'foreign_key'` field-spec key, the hard-ownership policy, and how the two
  layers divide responsibility (current-state voice, no migration narrative).
- `docs/testing.md`: fixture registration relies on declared FK cascades for
  child rows; note the referential-integrity gate.
- `docs/example_class.php`: document the `'foreign_key'` spec key on the
  annotated example field.

## Acceptance

Reproduction is now deterministic, so acceptance is concrete:

1. **Pre-fix reproducer, post-fix green.** With orphan rows present in the
   upcoming ID window, `php tests/run.php db --filter=vault` fails; after
   Parts 1–3 land, the same command over the same window is green.
2. **20 consecutive full `db` runs green** on an unchanged tree.
3. `information_schema.table_constraints` shows every declared FK; the
   referential-integrity test passes in the `safe` gate.
4. Running `update_database` twice in a row moves no sequence backwards
   (compare `last_value` across all serial sequences before/after).
5. Before/after a full `db` run: vault-family row counts and orphan census
   unchanged at zero residue.
6. Findings recorded in `specs/test_estate_audit.md` alongside the estate
   history (this spec's root cause is the write-up; link it from there).

## Implementation record (2026-07-18)

All five parts landed the same day the fix was specified:

1. **Forward-only sequences** — `DatabaseUpdater::syncSequenceForward()` is the
   shared helper (handles the `is_called=false` edge where the next `nextval`
   returns `last_value` itself). `update_database` Step 5, `fix_duplicate_keys`,
   and `fix_sequences` all route through it. Verified: two consecutive
   `update_database` runs report "Checked 82 sequences, advanced 0" — nothing
   moves backwards.
2. **Foreign keys materialized** — `DatabaseUpdater::manageForeignKeys()`
   (create missing / drop-and-recreate mismatched / orphans block with a loud
   error block), wired into `update_database` as the FOREIGN KEYS step (after
   migrations, so a cleanup migration can unblock it in the same run) and into
   `PluginManager` install, activate, and sync paths. The hard-ownership audit
   added one new declaration: `pkc_passkey_credentials.pkc_usr_user_id →
   usr_users ON DELETE CASCADE` (a credential row without its user is exactly
   what the floor's liveness probe must never see).
   `uew_pkc_credential_id` deliberately carries no DB FK: its lifecycle is
   soft-delete-managed (`cleanupRevokedCredential`) and the vault FK already
   guarantees hard cleanup. All 6 declarations are live constraints.
3. **Cleanup migration 150** (`cleanup_orphaned_fk_rows.php`) — removed 1 stray
   fixture user and 1,894 orphan rows (1,860 wrappings, 17 passkeys, 1 vault,
   16 vault-plugin rows) in one multi-pass sweep driven by the same
   `foreign_key` declarations. Idempotent; second run removes nothing.
4. **Harness** — `make_user()` emails carry a per-process random token; the
   fixture cascade comment now states the real constraint; the concurrency
   suite's hand-rolled compensating DELETE is gone (all suites share one
   teardown contract).
5. **Gate test** — `tests/schema/referential_integrity_test.php` (tier `safe`)
   passes 16/16 and runs in every gate invocation.

Acceptance results:

- `safe` 38/38 (751 checks), including the new integrity gate.
- Full `db` tier: 106/106 tests, 2,441 checks, 0 failed.
- 20 consecutive `db --filter=vault` runs green (each allocating fresh vault
  IDs across the formerly contaminated range), where pre-fix the same command
  failed on the ID ranges carrying orphans.
- Post-run residue census: 0 stray users, 0 orphan wrappings, 0 stray
  passkeys; every remaining vault/wrapping row belongs to a real account.
- `update_database` is idempotent and rewinds nothing (see part 1).

## Out of scope / related

- The `test-db` tier's independent redness (`models_crud` 66/85 failing on a
  clean tree) is a separate investigation — likely worth checking against the
  same two defects first, but not gated on this spec.
- `specs/test_estate_audit.md` T8 (APCu in the gate) and T17 (recovery
  concurrency) history stands; T17's "unsupported key length" load-flake was
  a different bug, fixed 2026-07-18.
