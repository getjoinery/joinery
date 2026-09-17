# Shared prefixes — RelayCloudProvision takes `rcl`

**Status: BUILT 2026-09-17.** Mailbox migration `rcl_001_relay_cloud_provision_prefix`
ran on dev (0 provisions in flight): old table and sequence gone, the two
declared `rcl_…` locators seeded in the registry and the three `rcp_…` rows
(one of them the undeclared SSH-era key) removed, both foreign-key rules
re-registered, reconcile clean; db gate 408/408 with relay_birth 33/33,
relay_cloud_provision 45/45, relay_upgrade 69/69. Outstanding: one release,
and the next real relay birth as the with-rows proof. Pattern:
`specs/implemented/shared_prefixes_first_three.md`; after
`specs/implemented/shared_prefix_content_version.md`, before
`shared_prefix_inbound_email_filter.md`.

## Why

`rcp` is carried by `Recipe` (joinery_ai) and `RelayCloudProvision`
(mailbox). Recipe is the AI subsystem's central object — AI-readable and
named by three foreign-key columns (`aip_`, `aqa_`, `rcr_`). A provision is
the transient record of one relay birth, named by nothing, with 0 rows
outside a live run. The provision moves, and takes a prefix in the mailbox
plugin's relay family: `rci` relay client identities, `mrl` mailbox relays,
`rcl` relay cloud provisions.

## What changes

| model | now | becomes |
|---|---|---|
| `RelayCloudProvision` | `rcp` / `rcp_relay_cloud_provisions` / pkey `rcp_relay_cloud_provision_id` | `rcl` / `rcl_relay_cloud_provisions` / pkey `rcl_relay_cloud_provision_id` |

Every column moves with its prefix (20 with the key: state, provider fields,
the instance record, `rcl_sealed_token`, `rcl_sealed_run_token`,
`rcl_sealed_ssh_key`, timestamps). File name and class stay.

**The sealed-secret declarations move with the columns.** Two columns are
declared as sealed-secret locators in `plugins/mailbox/plugin.json`
(`rcp_relay_cloud_provisions.rcp_sealed_token`,
`…rcp_sealed_run_token`; the registry on dev also carries
`…rcp_sealed_ssh_key`), and `SecretBox::seal()` is called with those exact
strings in the model. A locator is a declaration check — it is not bound
into the ciphertext — so an in-flight sealed value stays readable after the
rename; the declarations and the three `seal()` call sites take the new
strings. The registry (`ssr_sealed_secret_registry`) is seeded from the
declarations, so the new locators appear on the next `update_database`; the
three old rows would read as orphans (an alarm), so the migration deletes
them. No other table stores a locator.

No foreign-key column names this table; not API-exposed; nothing in the
relay's own bundle (the sealer, the first-boot installer, the birth report)
carries a column name — `RelayBirthEndpoint` matches the run token
server-side.

**The sweep hazard.** `rcp_create_time`, `rcp_update_time`,
`rcp_delete_time` belong to both models. The sweep goes by the provision's
column list inside the files below; `plugins/joinery_ai/**` keeps every
`rcp_` (its `views/admin/runs.php` names `rcp_recipe_id` and `rcp_name`).

## Inventory

`plugins/mailbox/data/relay_cloud_provisions_class.php` (49),
`includes/RelayCloudProvisioner.php` (48), `includes/relay_admin.php` (24),
`includes/relay_section.php` (4), `includes/RelayBirthEndpoint.php` (3),
`includes/oauth_consumers/RelayCloudConsumer.php` (3),
`tasks/MailboxRelayReconcile.php` (1), `admin/admin_mailbox_fleet.php` (1),
`includes/MailboxAliasConfig.php` (1), `plugin.json` (the two locators),
tests `relay_cloud_provision_test` (36), `relay_birth_test` (15),
`relay_upgrade_test` (7); `specs/relay_without_a_shell.md` is live and
follows. Historical migrations keep the old names.

## How the database moves

**Mailbox plugin migration `rcl_001_relay_cloud_provision_prefix`** in
`plugins/mailbox/migrations/migrations.php` (plugin table, so plugin
migration; runs inside `PluginManager::sync()` after `syncTables()` has
created `rcl_relay_cloud_provisions`): copy every row with its id, carry the
sequence, drop the old table and sequence, then
`DELETE FROM ssr_sealed_secret_registry WHERE ssr_locator LIKE
'rcp_relay_cloud_provisions.%'`. `to_regclass`-guarded and idempotent. A
node mid-birth at upgrade time is the one row that matters: the copy keeps
its state and sealed values, and the birth report matches the run token on
the renamed row. Plugin version bumps at build.

On dev: `update_database` (owner runs it), test-database refresh. The
registry's new rows appear on the same run and reconcile to `absent`.

## Gates

- validator on the model — the shared-prefix advisory goes quiet for `rcp`.
- `php tests/run.php db --changed`: `relay_cloud_provision`, `relay_birth`,
  `relay_upgrade`, `models_crud` / `multi_models_crud`, `sealed_secret_*`
  and the reconciler suites (no orphan named `rcp_…`),
  `deletion_rule_registration`, `class_autoloader`.
- Sealed-secret registry on dev after the run: three `rcl_…` rows, no
  `rcp_…` row, reconcile clean.
- Live proof: one release, and **the next relay birth** — a provision
  created, credential sealed on the renamed row, born, reported, erased at
  terminal state. Not to be forced; it rides the next real birth.

## Out of scope

`cnv` (before this) and `fil` (after). Recipe keeps `rcp`.
