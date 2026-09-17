# Model naming scheme — conformance sweep

**Status: IMPLEMENTED 2026-09-17. Migrations 185–191 built, rehearsed with rows
and applied on dev; full db gate 434/434. Outstanding: one release carries them
to the fleet, and the first node with DNS rows, many jobs or a long login
history is the live proof of 187, 189 and 190.**

## The scheme

One data model, one place for each name. For a model whose table holds
`examples` under the prefix `exm`:

| Thing | Name | Rule |
|---|---|---|
| `$prefix` | `exm` | exactly 3 lowercase letters; unique across all models |
| `$tablename` | `exm_examples` | `{prefix}_{plural}` |
| file | `data/examples_class.php` | `{plural}_class.php` — the table name minus its prefix |
| class | `Example` | PascalCase singular; a plugin may namespace (`AiConversation`, `SdDevice`) to avoid a collision |
| collection | `MultiExample` | `Multi{Class}` |
| `$pkey_column` | `exm_example_id` | `{prefix}_{singular}_id` — the column names its table, so any column carrying it elsewhere (`ord_exm_example_id`) is self-describing |
| every column | `exm_*` | carries the prefix |
| timestamps | `exm_create_time`, `exm_update_time`, `exm_delete_time` | only `{prefix}_delete_time` gets the `deleted` filter and `delete()`/`undelete()` |
| foreign key | `exm_usr_user_id` | `{prefix}_{target_prefix}_{target_singular}_id`; a role-named or abbreviated column (`rcp_owner_user_id`, `bkh_bkt_target_id`) declares `source_table` in `$foreign_key_actions` |

Where it is enforced:

- `maintenance_scripts/dev_tools/validate_php_file.php` model-contract pass —
  prefix, table, column prefixes, pkey serial, Multi present, FK actions
  declared are **errors**; file name, pkey shape, timestamp names, Multi
  name, shared prefix are **advisories**.
- `includes/scaffold/ScaffoldGenerator.php` mints every name from `prefix`
  + `entity` + `plural`, so a scaffolded model conforms by construction.
- `docs/example_class.php` is the copy-from template and now shows every
  name in the conforming form.

## Inventory (2026-09-16)

192 models — 104 core, 88 plugin. Every one passes the hard contract. The
drift was in what nothing enforced:

| Finding | Count | State |
|---|---|---|
| F1 file name not `{plural}_class.php` | 54 | FIXED — 53 renamed; `visitor_stats_rollup_class.php` holds two models and is named for the first |
| F2 pkey not `{prefix}_{singular}_id` | 23 | FIXED — D1, migration 189 |
| F3 timestamp spelled `_created_time` / `_updated_time` / `_modified_time` / `_modify_time` | 19 columns on 15 models | FIXED — `act` under D3, the other 18 under D2 (migration 188) |
| F8 tables on dev with no model | 17 | RESOLVED — one became a model (190), ten dropped (191), one kept on the record, four are reference data |
| B1 `act_deleted` bool instead of `act_delete_time` | 1 | FIXED — D3, migration 185 |
| F4 FK column abbreviates the target entity | 17 | FIXED — D4, migration 186 |
| F5 prefix shared by two models | 6 | OPEN — Part 3 |
| F6 `MultiEventSessions` | 1 | FIXED |
| F7 class name unrelated to table | 3 | FIXED — D6 (two class renames, one table rename, migration 187) |

## Part 2 — done

- A1 `docs/example_class.php`: `exm_example_id`, `exm_create_time`,
  `exm_update_time`, FK target `usr_user_id`, file-naming instruction.
- A2 scaffolder emits `data/{plural}_class.php`; its four logic templates no
  longer `require_once` the data class (classes resolve by name).
  `docs/scaffolding.md` and `tests/scaffold/scaffold_hardening_test.php`
  follow.
- A3 validator advisories for file name, pkey shape, timestamp names.
- A4 `MultiEventSessions` → `MultiEventSession` (8 files).
- F1 53 data files renamed with `mv` (not `git mv` — nothing staged); every
  `require_once` and doc reference rewritten (434 files). `specs/implemented/`
  untouched. The autoloader maps by tokenizing and guards a stale cached path
  with `is_file`, so no cache flush is needed.

## Part 3 — the remaining deviations, one decision each

### Mechanism for renaming a column

A column rename is a schema change, and `update_database` does not rename.
Its order is fixed: **add** every column the spec declares → run
**migrations** → **drop** every column the spec no longer declares (the
cleanup pass is deferred past migrations for exactly this reason — see
`DatabaseUpdater::processAdvancedColumnOperations`). So by the time a
migration runs, the new column already exists and a bare `RENAME COLUMN`
would fail. The shape that fits the order:

1. Rename the key in `$field_specifications` and every code reference.
2. Add a migration whose `test` checks the **old** column still exists and
   whose SQL is `UPDATE t SET new = old` — a backfill, not a rename. The
   cleanup pass then drops `old` on the same run.
3. A plugin table needs the `to_regclass` guard (the table exists only where
   the plugin is active — the 0.8.374 lesson).

Migrations 122–124 used a bare `RENAME COLUMN`; that predates the deferred
cleanup and is not the pattern to copy.

For a **primary key** the add pass creates the new column as a fresh
serial — filled from a **new** sequence, not with the old ids — so the
migration must copy `old → new`, move the primary-key constraint, and set
the new sequence to the old one's value, in one transaction, before any row
is read by its new key. Plus every `?mgn_id=` URL parameter, every `hidden`
form field, and the `del_deletion_rules` rows that name the column. That is
why F2 is the expensive one.

Column names are also the REST/AI surface. Of the models in F2/F3, these are
exposed: `Comment` (api read+write, ai read), `Passkey` (api read),
`CalendarEntry`, `ProductRequirementInstance`, `SeoPageMetadata` (ai read).
Renaming a column on those changes what a client sees.

### D1 — F2, primary keys (23 models) — DONE 2026-09-17

Every primary key is `{prefix}_{singular}_id`: `mgn_managed_node_id`,
`mjb_management_job_id`, `mgh_managed_host_id`, `ajr_agent_join_request_id`,
`inc_incident_record_id`, `htr_hosted_trial_id`, `cca_customer_cloud_account_id`,
`cvp_customer_cloud_provision_id`, `rcp_relay_cloud_provision_id`,
`rdm_registered_domain_id`, `bkt_backup_target_id`, `bkh_backup_history_id`,
`del_deletion_rule_id`, `ahb_agent_heartbeat_id`, `ssr_sealed_secret_registry_id`,
`aip_recipe_item_log_id`, `aim_conversation_message_id`,
`aia_message_attachment_id`, `rcr_recipe_run_id`, `pas_persona_allowed_sender_id`,
`pbs_persona_blocked_sender_id`, `cal_entry_id`, `cex_entry_exception_id`.
140 files rewritten (PHP, docs, one shell gate); the Go agent and JS files
named none of them. Every `?mgn_id=` URL and hidden field is
`?mgn_managed_node_id=`; writers and readers were cross-checked.

**Migration 189 (`migrations/primary_keys_name_their_table.php`) is the
in-place primary-key shape** — the table keeps its name, so 187's
copy-into-a-new-table does not apply. What a deploy does, in order: the
spec pass adds the new column with its own sequence; the updater's
primary-key fix fills it from that sequence (1..N in physical order, not
the old ids), drops the old key and makes the new column the key — or
fails to, on a table with a dependent FK constraint (`mgn`, `bkt`), and
logs it. Then the migration, per table: drop the primary key **CASCADE**
(a dependent FK goes with it; the foreign-key step that follows migrations
re-materializes every declared one — `bkh → bkt` did); `UPDATE new = old`
with the key off (Postgres checks uniqueness per row during an UPDATE, so
swapping ids in place would collide mid-statement); put the key on the new
column; `setval` the new sequence by the name read off the column default;
drop the old column and its sequence by name.

Rehearsed on dev's real tables in a rolled-back transaction, simulating
the spec pass and the PK-fix first: 23 tables, every id kept (4,155 jobs
still joined to their 14 nodes, 79 history rows to their target), keys
moved, next id = max + 1 on every table, old columns and sequences gone.
Applied for real with the same result; `setval` is not transactional, so
the one sequence the rehearsal had advanced was reset first.

`bke_backup_key_escrow` (an F8 orphan) had a real FK to `mgn_id`; the
CASCADE took it and nothing re-creates it, because no model declares the
table. It is the F8 decision's problem, not this one's.

The two apparent leftovers, `ccu_coupon_code_use_id` on
`ccu_coupon_code_uses` and `vsu_visitor_daily_uniques_id` on
`vsu_visitor_daily_uniques`, conform (`+s`; uncountable) — only the
inventory script's naive singularizer flagged them.

### D2 — F3, timestamp spelling (18 columns, 14 models) — DONE 2026-09-17

`_created_time` → `_create_time` (aqa, cmt, del, imi, pkc, pks, pri, uev,
uew, vle, vlk); `_updated_time`, `_modified_time`, `_modify_time` →
`_update_time` (imi, uev, vle, vlk; abt, abv; spm). 38 files (PHP and docs)
rewritten; no JS, Go or SQL named any of them outside PHP. Migration 188
(`migrations/timestamp_columns_platform_names.php`) is the 186 shape: per
column, skip an absent table or a column already gone, copy `old → new`,
drop `old`. Applied on dev: 250 deletion-rule rows, 260 requirement
instances, 48 wrappings, 11 passkeys among them; the true timestamps
survived (a `create_time` with a `now()` default is filled by the spec pass
on every existing row, and the copy overwrites that with the old value).

Four of the models are exposed (`Comment` REST read+write, `Passkey` REST
read; `ProductRequirementInstance`, `SeoPageMetadata` AI read), so a client
reading those columns by name sees the new spelling. There are no
production users yet; that is the reason to do it now.

Two columns that look like the pattern and are not: `fil_content_modified_time`
and `fup_content_modified_time` are the uploaded file's own mtime, a fact
about the content, not the row. They stay.

### F8 — tables with no model (17), found under D2 — RESOLVED 2026-09-17

Two kinds hid under "no model declares it", and telling them apart took
evidence per table (git history, the implemented specs, row counts, last
writes), not the absence of references:

**Live, written by a hand-rolled class (2).** A table the platform writes
outside `update_database`, the deletion engine and the validator is the
deepest non-conformance there is.

- `log_logins` — every sign-in, sign-out and cookie resume; 80,671 rows,
  written minutes before it was found. `data/login_class.php` (`LoginClass`,
  its own `CREATE TABLE`, a `(user, time)` pair for a key, an `inet` column)
  is now `data/logins_class.php`: `Login` / `MultiLogin` on `log_logins`,
  serial `log_login_id`, `log_ip varchar(45)` like every other IP column,
  `log_usr_user_id` cascading from the user. `Login::record()` stamps
  `usr_lastlogin_time` and saves the row in one transaction, as before; the
  four sign-in paths and logout call it; the admin user page reads the
  history through `MultiLogin`; the activity report's join stays raw SQL
  (ad-hoc reporting). **Migration 190** fills `log_login_id` from its
  sequence, moves the key, carries the sequence, converts the addresses
  with `host()` and drops the inet column — the spec could not declare
  `varchar(45)` on the inet column directly, because the spec pass runs
  before migrations and an uncastable type change halts them. Applied on
  dev: 80,671 ids, 80,671 addresses; the model was exercised live. Bringing
  the table under the deletion engine put it under the referential-integrity
  check for the first time, which found 14,571 rows of users deleted before
  the table had any rule; the migration removes that backlog once (the
  model cascades a person's history with the person), and dev's was removed
  by hand — 66,100 rows remain.
- `ers_recurring_email_logs` — looked live (a class writes it) and was
  not: `RecurringMailer` is a 2021 class with no task or route, its last
  two callers went in 8ec04cde and 19266f40, the table is empty and the
  `equ_ers_recurring_email_log_id` it fed is NULL on every queued email.
  Retired: the class, the column and `QueuedEmail`'s two stats methods.

**Retired on the record (10 dropped, migration 191)** — each with its
replacement named in the migration file: the six `ctld*` tables (ScrollDaddy
renamed `ctld → sd`), `cls_cart_logs` (no code since 2021), the recurring
mailer's log, `iem_inbound_emails` (the Mailgun-era store), `rqt_requirement_types`
(types are auto-discovered), and `lck_license_keys` — dropped **only when
empty**; the keys live on `own_ownerships.own_license_key` and a node whose
rows were never carried over keeps the table and says so. `update_database`
never drops a table, so their sequences (not `OWNED BY`) are dropped by
name too.

**Kept, deliberately (1).** `bke_backup_key_escrow` was on the drop list on
the strength of zero code references, and would have been wrong: its rows
are the sealed node keys for pre-envelope archives still in buckets and the
agent-signing-key backup — two implemented specs say keep. It has no model
and never will; it lost its FK constraint to nodes in 189, which the specs
say is not what identifies its rows.

**Reference data (4):** `cco_country_codes`, `country`, `timezone`, `zone`
— read without a model, seeded by the installer and the test-db copy.

### D3 — B1, `act_deleted` (ActivationCode) — DONE 2026-09-16

A bool flag instead of `act_delete_time`, so activation codes sat outside
the platform's soft-delete machinery (`deleted` filter, `delete()`,
`undelete()`, the retention timer), and the flag recorded that a code was
spent but never when.

Built: spec declares `act_delete_time` (`timestamp(6)`, nullable) and
`act_create_time` (the table's `_created_time`, taken in the same change);
`includes/Activation.php` voids with `SET act_delete_time = now()` and
validates with `act_delete_time IS NULL`; `adm/admin_phone_verify.php`, the
comment in `logic/recovery_verify_logic.php` and the two account-security
tests follow. Migration 185 (`migrations/activation_codes_delete_time.php`)
copies `act_created_time` across, stamps every spent code with its create
time (nothing ever recorded the real moment), and drops both old columns —
the runner holds the transaction, so the file must not open one. Applied on
dev: 8,400 create times copied, 1,267 spent codes stamped.

This is the pattern for every column rename below: spec change, callers,
a migration file that copies then drops, guarded on the old column existing.

### D4 — F4, foreign keys that abbreviate the entity (17 columns) — DONE 2026-09-16

`ajr_mgn_node_id` resolved to `mgn_managed_nodes` only while exactly one
model claimed `mgn`; the engine matches the entity in the name against the
candidate tables, and `node` matches none. Owner chose the rename over a
`source_table` line, so the column reads back to its table by name alone.

Built: every column carries the full entity —

| old | new |
|---|---|
| `ajr_mgn_node_id` | `ajr_mgn_managed_node_id` |
| `cvp_mgn_node_id` | `cvp_mgn_managed_node_id` |
| `inc_mgn_node_id` | `inc_mgn_managed_node_id` |
| `mjb_mgn_node_id` | `mjb_mgn_managed_node_id` |
| `rdm_mgn_node_id` | `rdm_mgn_managed_node_id` |
| `mgh_mgn_host_node_id` | `mgh_mgn_managed_node_id` (the role word `host` dropped — a host row carries one node pointer, so it said nothing; a role word belongs only where a table points at the same target twice, and then before the target prefix with `source_table`, as `ntf_source_usr_user_id`) |
| `mgn_mgh_host_id` | `mgn_mgh_managed_host_id` |
| `cvp_cca_account_id` | `cvp_cca_customer_cloud_account_id` |
| `htr_cvp_provision_id` | `htr_cvp_customer_cloud_provision_id` |
| `mfd_mft_slot_id` | `mfd_mft_mailbox_fleet_slot_id` |
| `mft_mfs_shard_id` | `mft_mfs_mailbox_fleet_shard_id` |
| `rcp_mfs_shard_id` | `rcp_mfs_mailbox_fleet_shard_id` |
| `aia_aim_message_id` | `aia_aim_conversation_message_id` |
| `aip_rcr_run_id` | `aip_rcr_recipe_run_id` |
| `pro_emt_receipt_template_id` | `pro_emt_email_template_id` |
| `uew_pkc_credential_id` | `uew_pkc_passkey_credential_id` |
| `bkh_bkt_target_id` | `bkh_bkt_backup_target_id` (its `source_class` override is gone) |

92 files (PHP, docs, active specs) rewritten; no JS or agent Go code named
any of them. Migration 186 (`migrations/foreign_keys_name_their_entity.php`)
walks the 17, skipping a table that is absent (plugin not active) or already
migrated, copies `old → new` and drops `old`. Applied on dev: 3,667 job
rows, 8,087 recipe-log rows, 78 backup-history rows among them; all 17
deletion rules re-registered on the new names, none left on the old; the
one real FK constraint (`bkh`) re-materialized.

Four of the columns are NOT NULL (`inc`, `htr`, `mfd`, `mft`). The add pass
creates them nullable and the NOT NULL step runs before the migration has
filled them, so it defers with a note; the next `--upgrade` run applies it.
The model layer's `required` holds the line in between.

### D5 — F5, shared prefixes (6)

`abt` AbTest/AppBridgeToken, `bkt` BackupTarget/BookingType, `cnv`
ContentVersion/Conversation, `del` DebugEmailLog/DeletionRule, `fil`
File/InboundEmailFilter, `rcp` Recipe/RelayCloudProvision.

The resolver disambiguates by matching the column's entity against the
candidates' table names, so nothing is broken. But a shared prefix is why
F4 is a latent bug at all, and a new model choosing a prefix has no
registry to check against except the validator's advisory.

Options:

- **Re-prefix one of each pair** — a rename of every column in the table
  (`bkt_id`, `bkt_name`, … → `bkp_…`) and the table itself. The most
  invasive change in this spec; six times.
- **Leave them; the scaffolder refuses a taken prefix and the validator
  advises.** No new pair can appear by accident.

Recommendation: leave them. Take D4 instead, which removes the only
consequence.

### D6 — F7, class names unrelated to their table (3) — DONE 2026-09-17

Two of the three conforming names were already taken by other classes,
which is how the models got their odd names in the first place:

| model | table | was | now |
|---|---|---|---|
| form-error log | `lfe_log_form_errors` | `FormError` | `LogFormError` (+ `MultiLogFormError`, `LogFormErrorException`); its static logger, which shared the class's name, is `log()` |
| stored email template | `emt_email_templates` | `EmailTemplateStore` | `EmailTemplate` (+ `MultiEmailTemplate`, `EmailTemplateException`). The template *renderer* that held the name — `includes/EmailTemplate.php` — is `EmailTemplateRenderer` in `includes/EmailTemplateRenderer.php` (22 files) |
| managed DNS record | was `dnr_dns_records` | `ManagedDnsRecord` | class unchanged; the **table** is `dnr_managed_dns_records`, pkey `dnr_managed_dns_record_id`, file `managed_dns_records_class.php`. `DnsRecord` stays the wire value object (`includes/dns/DnsRecord.php`, 499 references) — a different thing, and the model's `Managed` was the accurate half of the pair |

None of the three is a REST or AI resource, so no URL or stored class name
changes; settings and recipe rows were checked for the old names (none).

**Migration 187 (`migrations/managed_dns_records_table.php`) is the
table-copy shape D1 needs.** The spec pass creates the new table, empty,
with its own sequence; the migration `INSERT … SELECT`s every row across
with the primary key kept, sets the new sequence past the highest id, and
drops the old table *and its sequence*. Two things learned rehearsing it
(old-shape table with rows, in a rolled-back transaction):

- `update_database` names a pkey's sequence `{table}_{pkey}_seq` and does
  **not** make it `OWNED BY` the column, so `pg_get_serial_sequence()`
  returns NULL, `setval(NULL, …)` silently does nothing, and `DROP TABLE`
  leaves the old sequence behind. Read the sequence name off the column
  default; drop the old sequence by name.
- `setval` is not transactional: a rehearsal inside a rolled-back
  transaction still moves the live sequence. Reset it afterwards.

A primary-key rename (D1) is this same migration on the same table name:
new pkey column in the spec, copy with the id mapped, sequence carried.

## Order

D3 → D4 → D6 → D2 → D1 → F8 done: migrations 185–191, one deploy. D5
stays closed. Commit and release, then this spec moves to implemented/.
