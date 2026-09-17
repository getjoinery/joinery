# Shared prefixes — ContentVersion takes `cvn`

**Status: BUILT 2026-09-17.** Migration 193 ran on dev: 3,812 rows copied
with their ids, 3,323 of them in previous/next chains with no dangling link
after the move, sequence carried (3817), old table dropped, deletion rule
re-registered; the post, page, email-template and component editors render
their version selector and load a named version; db gate 408/408.
Outstanding: one release. First of the three remaining pairs after
`specs/implemented/shared_prefixes_first_three.md`, which is the pattern.

## Why

`cnv` is carried by `Conversation` and `ContentVersion`. Conversation is the
messaging system — API-exposed, AI-readable, federated through the messenger
plugin, and named by four foreign-key columns (`msg_`, `cnp_`, `ckg_`, `crp_`).
ContentVersion is the edit history behind six admin edit pages and is named by
nothing. The version model moves.

## What changes

| model | now | becomes |
|---|---|---|
| `ContentVersion` | `cnv` / `cnv_content_versions` / pkey `cnv_content_version_id` | `cvn` / `cvn_content_versions` / pkey `cvn_content_version_id` |

Every column moves with its prefix: `cvn_type`, `cvn_foreign_key_id`,
`cvn_title`, `cvn_description`, `cvn_content`, `cvn_previous_version_id`,
`cvn_next_version_id`, `cvn_usr_user_id`, `cvn_create_time`, `cvn_delete_time`
(11 with the key). The file name and class stay: `data/content_versions_class.php`,
`ContentVersion`, `MultiContentVersion`.

No foreign-key column names this table, so no sibling column moves. It is
not `api_readable`/`api_writable` (no CRUD endpoint, no client carries the
column names). `cvn_type` holds entity class names (`Page`, `PageContent`,
`Post`…), `cvn_foreign_key_id` the entity's id, `cvn_content` the entity's
serialized fields keyed by the *entity's* column names — none of it names
this table's columns, so no stored data changes.

**The sweep hazard.** `cnv_create_time` and `cnv_delete_time` belong to both
models. The sweep goes by ContentVersion's column list inside the files
below, never by `cnv_` across the tree; `data/conversations_class.php`, the
messenger plugin and the conversation views keep every `cnv_`.

## Inventory

`data/content_versions_class.php` (40 refs, the model plus the versions
panel), `adm/admin_component_edit.php`, `adm/admin_post_edit.php`,
`adm/admin_email_template_edit.php`, `adm/admin_page_edit.php`,
`plugins/items/admin/admin_item_edit.php`,
`plugins/event_manager/admin/admin_location_edit.php`,
`plugins/event_manager/admin/admin_event_edit.php`,
`plugins/event_manager/admin/logic/admin_event_edit_logic.php` (2–5 each),
`tests/integration/deletion_rule_registration_test.php` (the shared-prefix
case moves to `fil`), `docs/deletion_system.md` (the ambiguous-prefix
example moves to `fil`), `class_autoloader_test` (the shared case moves to
`fil`). Historical migrations keep the old names.

## How the database moves

Migration 187's pattern, as migration 192 did it: the additive pass creates
`cvn_content_versions` from the spec, empty; **core migration 193**
`content_version_prefix.php` copies every row with its id (dev: 3,812 —
the first with-rows proof of the copy at scale), carries the sequence, drops
the old table and its sequence. Idempotent on `to_regclass`. No column to set
NOT NULL, no sibling column. `test` SQL: 1 while `cnv_content_versions` is
absent. `previous_version_id`/`next_version_id` are plain ints pointing at
rows of this same table; they copy with their values and stay valid because
ids are kept.

On dev: `update_database` (owner runs it), test-database refresh.

## Gates

- validator on the model — the shared-prefix advisory goes quiet for `cnv`.
- `php tests/run.php db --changed`: `models_crud` / `multi_models_crud` over
  ContentVersion, `deletion_rule_registration`, `deletion_cascade`,
  `tables_without_model`, `class_autoloader`, the admin edit pages' suites.
- The six edit pages render with their versions panel as a throwaway
  superadmin on dev; a page with history shows it.
- Live proof: one release; every node has content versions, so every node
  proves the copy with rows.

## Out of scope

`fil` and `rcp` — their own specs. FormWriter's and the deletion engine's
candidate walk stay until the last pair is done.
