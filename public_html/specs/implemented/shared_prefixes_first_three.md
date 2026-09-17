# Shared prefixes — the first three

**Status: BUILT 2026-09-17.** Migration 192 and the bookings plugin migration
`002_booking_type_prefix` rehearsed on dev with rows in every table (one A/B
test with a variant, one debug-email row, two booking types with four
bookings): every row carried with its id, sequences past the highest id,
old tables and columns gone, deletion rules rebuilt on the new names, the
seven affected pages render. Outstanding: one release carries it to the
fleet; the first node with bookings active is the plugin-migration proof.
Follows `specs/implemented/model_naming_scheme_conformance.md` § D5, which
left the six shared prefixes in place; this takes three and is the proof of
the pattern. The other three (`cnv`, `fil`, `rcp`) wait on how this goes.

## Why

A model's prefix is supposed to be its identity: `abt_status` names one
table, and nothing has to ask which. Six prefixes are carried by two models
each, so today two resolvers tie-break by which model declares the column
(the deletion engine, and since FormWriter 2.26.0 the form validator), and a
new model has only an advisory telling it a prefix is taken. Nothing is
broken; the prefix just is not an identity yet. Retiring a pair at a time
makes it one.

## What changes

| model | now | becomes | why this one |
|---|---|---|---|
| `AbTest` | `abt` / `abt_tests` / `data/tests_class.php` / pkey `abt_test_id` | `abx` / `abx_ab_tests` / `data/ab_tests_class.php` / pkey `abx_ab_test_id` | `abt` stays with `AppBridgeToken`, the mobile app's transport. The table also takes the scheme's name: `abt_tests` was a table called *tests* whose class was `AbTest`. |
| `BookingType` | `bkt` / `bkt_booking_types` / pkey `bkt_booking_type_id` | `bty` / `bty_booking_types` / pkey `bty_booking_type_id` | `bkt` stays with `BackupTarget`, which the server-manager agent and the backup runner name in job payloads. |
| `DebugEmailLog` | `del` / `del_debug_email_logs` / pkey `del_debug_email_log_id` | `dbl` / `dbl_debug_email_logs` / pkey `dbl_debug_email_log_id` | `del` stays with `DeletionRule`, which every model's cascade names. |

Every column moves with its prefix (`abx_status`, `bty_slug`, `dbl_message`
…). The two foreign-key columns that name these tables follow the scheme's
`{prefix}_{target_prefix}_{target_singular}_id` rule:

| column | table | becomes |
|---|---|---|
| `abv_abt_test_id` | `abv_variants` | `abv_abx_ab_test_id` |
| `bkn_bkt_booking_type_id` | `bkn_bookings` | `bkn_bty_booking_type_id` |

Nothing else names these tables: the one `source_class` override
(`bkn_bookings`, declared because `bkt` was shared) goes, since the
convention now resolves it;
none of the three is `api_readable`/`api_writable` (no CRUD endpoint, so no
mobile client carries the column names), and no stored JSON carries a column
name — `abv_overrides` keys the *entity's* fields, and the A/B cookie and
visitor-event stash carry bare ids.

## Inventory

**AbTest — 11 columns.** `data/tests_class.php` (71 refs; becomes
`data/ab_tests_class.php`), `data/variants_class.php` (the FK column, 3),
`adm/admin_ab_tests.php` (18), `tests/functional/ab_testing/ab_testing_test.php`
(24), `docs/ab_testing.md` (1), `docs/testing.md` (1, the resolver example).
`views/page.php`, `includes/ComponentRenderer.php`, `adm/admin_page.php`,
`adm/admin_component_edit.php`, `includes/SessionControl.php` name the class
only and do not change.

**BookingType — 30 columns.** The bookings plugin: `data/booking_types_class.php`,
`data/bookings_class.php` (the FK column), the four admin pages, seven logic
files, three views, `BookingItemSource`, `NativeSchedulingProvider`,
`SchedulingServiceProvider`, `BookingEmailsTask`, `booking_flow_test`,
`docs/overview.md`; core `logic/booking_logic.php`; `tests/lib/harness.php`
(fixture sweep family) and `tests/schema/referential_integrity_test.php`
(named fixture) list the table; `data/deletion_rules_class.php` and
`docs/deletion_system.md` used `bkn_bkt_booking_type_id` as the worked
example of the resolver (the example moves to `cnv`); `docs/testing.md` lists the table.
`specs/external_scheduling_integrations.md` is a live spec and follows.

**DebugEmailLog — 5 columns.** `data/debug_email_logs_class.php`,
`includes/EmailSender.php` (3 sets), `adm/admin_debug_email_logs.php` (5),
`tests/email/send_report_test.php` (2), `tests/integration/deletion_cascade_test.php`
(1), `docs/email_system.md` (7), `docs/deletion_system.md` (1).

Historical migrations (`migrations/migrations.php`,
`timestamp_columns_platform_names.php`) keep the old names: they describe
the database as it was when they ran.

## How the database moves

The pattern is migration 187 (`migrations/managed_dns_records_table.php`):
`update_database`'s additive pass creates the new table from the renamed
spec, empty, before migrations run; the migration copies every row across
with its id, moves the new sequence past the highest id, drops the old table
and its sequence. Idempotent: no old table, nothing to do. The FK column in
the sibling table is a rename in place (migration 186's pattern,
`foreign_keys_name_their_entity.php`), guarded by `information_schema.columns`;
`update_database` re-points the foreign-key constraint from the declared
`$foreign_key_actions` on the same run, and the deletion-rule registry is
rebuilt from the specs.

- **Core, migration 192** `shared_prefixes_first_three.php`: `abt_tests →
  abx_ab_tests` (copy, sequence, drop), `abv_variants.abv_abt_test_id →
  abv_abx_ab_test_id`, `del_debug_email_logs → dbl_debug_email_logs` (copy,
  sequence, drop). `test` SQL: 1 while neither old table exists.
- **Bookings plugin, migration `002_booking_type_prefix`** in
  `plugins/bookings/migrations/migrations.php`: `bkt_booking_types →
  bty_booking_types` (copy, sequence, drop), `bkn_bookings.bkn_bkt_booking_type_id
  → bkn_bty_booking_type_id`. It lives in the plugin because the table exists
  only where the plugin is active; plugin migrations run inside
  `PluginManager::sync()`, after `syncTables()` has created the new table.
  `to_regclass` guards both tables all the same.

On dev: `update_database` (owner runs it), then the test-database refresh,
since the model suites read the live schema.

## Gates

- `php maintenance_scripts/dev_tools/validate_php_file.php` on every touched
  class — the shared-prefix advisory goes quiet for these three.
- `php tests/run.php db --changed`: `ab_testing` (db), `booking_flow` (db),
  `send_report` (safe), `deletion_cascade`, `deletion_rule_registration`,
  `referential_integrity`, `tables_without_model` (the old tables must be
  gone, not orphaned), `models_crud` / `multi_models` over the three models.
- `class_autoloader_test` 1.2 asserts `abt => [AppBridgeToken]` and uses
  `cnv` for the shared case; `deletion_rule_registration_test` 1.5 pins the
  resolver on `cnv`/`fil` and `bty` as a single-owner prefix.
- The migration sets the variant column NOT NULL itself after the copy; the
  schema pass cannot apply the spec's NOT NULL to a column it has just added
  empty, and would otherwise only do so on the following run. Dev, where 192
  ran before that line was added, got it from the second pass.
- Live proof: one release; the first node with bookings active is the
  plugin-migration proof.

## Out of scope

`cnv` (ContentVersion/Conversation), `fil` (File/InboundEmailFilter), `rcp`
(Recipe/RelayCloudProvision). FormWriter's and the deletion engine's
candidate walk stay until those are done.
