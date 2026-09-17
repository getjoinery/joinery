# Shared prefixes — InboundEmailFilter takes `ief`

**Status: BUILT 2026-09-17.** Mailbox migration `ief_001_inbound_email_filter_prefix`
ran on dev: 46 filters copied with their ids, the sequence carried at the
old sequence's issued value (2262, above the highest surviving id 1450 —
sequences are forward-only), old table dropped, both foreign-key rules
re-registered; the filters page renders `ief_` fields with no `fil_` left,
a create through the real form saves (new id 2263), lists, and `matches()`
fires on it; db gate 408/408 with filter_import 44/44, mail_archive_import
85/85, mailbox_reader 79/79. Every prefix now names one model;
`deletion_rule_registration_test` 1.7 and `class_autoloader_test` 1.4 pin
that invariant. Outstanding: one release; jeremytunnell (real filters and
mail) is the with-rows proof. Last of the six pairs. Pattern:
`specs/implemented/shared_prefixes_first_three.md`.

## Why

`fil` is carried by `File` and `InboundEmailFilter`. File is the platform's
storage model — 25 columns, `fil_file_id` named by nineteen foreign-key
columns across core and plugins, a retention policy, declared indexes,
AI-readable. The mail filter is the mailbox plugin's rule row, named by
nothing. The filter moves, into the mailbox plugin's inbound family: `ied`
domains, `iea` aliases, `ieg` grants, `iel` logs, `ief` filters.

## What changes

| model | now | becomes |
|---|---|---|
| `InboundEmailFilter` | `fil` / `fil_inbound_email_filters` / pkey `fil_inbound_email_filter_id` | `ief` / `ief_inbound_email_filters` / pkey `ief_inbound_email_filter_id` |

Every column moves with its prefix (30 with the key: the scope columns, the
match columns, the action columns, `ief_name`, ordering, enabled, source and
import bookkeeping, timestamps). File name and class stay.

No foreign-key column names this table (a filter's own action columns name
labels, groups and aliases the other way round, and those keep their names).
Not `api_readable`/`api_writable`. The match and action values are stored in
the filter's own columns as plain values, not as JSON keyed by column name;
the Gmail filter import (`GmailFilterExportParser`) maps to the logic's own
field names and carries no `fil_` string. The filters page is a FormWriter
form whose field names are the column names — the sweep changes them
together with the logic that reads them.

**The sweep hazard is the largest of the six.** `fil_create_time`,
`fil_delete_time` and `fil_name` belong to both models, and the mailbox
plugin uses `File` heavily (attachments, imports, custody): 30-odd mailbox
files carry `fil_` for File and keep it. The sweep goes strictly by the
filter's column list inside the files below.

## Inventory

`plugins/mailbox/logic/mailbox_filters_logic.php` (247 — the filters page
logic and its FormWriter field names), `data/inbound_email_filters_class.php`
(132), `includes/mailbox_filters_panel.php` (40 — the panel markup and its
inline script), `includes/MailboxService.php` (10), `tasks/ApplyInboundEmailFilters.php`
(8), `docs/overview.md` (3). `includes/InboundEmailRouter.php` (the apply
path, `InboundEmailFilter::runForMessage`), `logic/admin_mailbox_domains_logic.php`
and `includes/GmailFilterExportParser.php` name the class only — the
`fil_` they carry is File's — and do not change; tests `filter_import` (16), `forward_consent` (11),
`filter_import_delete_disabled` (11), `mailbox_reader` (10),
`mail_archive_import` (8), `filter_mount_scope` (7);
`tests/integration/deletion_rule_registration_test.php`,
`docs/deletion_system.md` and `class_autoloader_test` lose their last
shared-prefix example (the checks become "no prefix has two owners").
`plugins/mailbox/migrations/migrations.php` ~line 370 names the old table
inside an existence-guarded historical step and stays as it is: on a fresh
install the guard finds no such table and the step is a no-op, which is
what it already is.

## How the database moves

**Mailbox plugin migration `ief_001_inbound_email_filter_prefix`** in
`plugins/mailbox/migrations/migrations.php`: copy every row with its id
(dev: 46), carry the sequence, drop the old table and sequence;
`to_regclass`-guarded, idempotent. Any unique index the spec declares is
created on the new table by the additive pass before the copy, so a
duplicate would refuse the copy loudly rather than pass silently — none is
expected. Plugin version bumps at build.

On dev: `update_database` (owner runs it), test-database refresh.

## Gates

- validator on the model — no shared-prefix advisory anywhere.
- `php tests/run.php db --changed`: the six filter/import suites above,
  `mailbox_reader`, `models_crud` / `multi_models_crud`,
  `deletion_rule_registration`, `class_autoloader`, `tables_without_model`.
- The filters page (`/profile/mailbox/filters`) renders, saves an edit to an
  existing filter and creates one, as a throwaway member on dev; a Gmail
  filter export imports; `ApplyInboundEmailFilters` applies a rule to a new
  message.
- Live proof: one release; jeremytunnell carries real filters and mail, and
  is the node that proves the copy and the apply path.

## After the last pair

With six prefixes retired, `FormWriterV2Base::detectModelFromFieldName()`'s
candidate loop and `DeletionRule::getSourceTableFromColumn()`'s entity
tie-break run over single-element lists. They stay: the scaffolder only
warns on a taken prefix, so a new pair can still be created on purpose, and
the walk is what keeps that from being a silent misresolution. The tests
that pin the walk switch to a fixture pair rather than a real one.

## Out of scope

`cnv` and `rcp` — their own specs, which go first.
