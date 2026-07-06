# Fix: the deletion-rule auto-detector guesses source tables instead of looking them up

**Status:** Ready for implementation (executor package — full accounting and fix
plan below; verified against live dev 2026-07-06).
**Reported:** Discovered while writing cleanup code for
`specs/implemented/joinery_ai_item_pipeline.md`'s test — a throwaway
`aip_recipe_item_log` row referencing a `RecipeRun` via `aip_rcr_run_id`
silently failed to cascade-delete when the run was deleted directly.
**Verified:** 2026-07-06 — guesser code, live mis-registered rule, and both
prior workaround comments all confirmed; full `del_deletion_rules` audit run
(results below).
**Related:** [[joinery_ai_item_pipeline]] (where this was found),
`plugins/joinery_ai/data/ai_conversation_messages_class.php` and
`plugins/inbound_email/data/inbound_email_mailbox_grant_class.php` (two
earlier, independently-discovered instances, each worked around locally).

## Plain-language summary

When a model declares a "this row belongs to that row" column (a
foreign-key-shaped column, e.g. `aip_rcr_run_id`), the platform is supposed to
figure out on its own which table that column points to, so that deleting the
parent row also cleans up the child rows. It does this by **guessing** the
parent table's name from the column name — splitting the column into words and
guessing a plural — instead of **looking up** the real table name from the
model class that actually declares it (which the platform already knows,
because every model declares its own table name as a plain PHP property).

When the guess is wrong, the cleanup rule silently registers against a table
name that doesn't exist. It doesn't error. It doesn't warn. The rule simply
never fires — child rows are silently orphaned when their parent is deleted
directly. **No data is deleted from the wrong place** (rules are looked up by
exact string match on the real table name at delete time, so a wrong string
just never matches) — this is a data-hygiene bug, not a data-safety one. But
it is entirely quiet, and the audit below shows it is far more widespread
than the three originally-known instances.

## How the guesser works today

`data/deletion_rule_class.php`:

```php
// registerModelRules(), ~line 86 — only 4-underscore-segment columns are even considered
if (preg_match('/^[a-z]+_[a-z]+_[a-z]+_id$/i', $column)) {
    $source_table = self::getSourceTableFromColumn($column);
    ...
}

// getSourceTableFromColumn(), ~lines 172-183
private static function getSourceTableFromColumn($column) {
    if (preg_match('/^[a-z]+_([a-z]+)_([a-z]+)_id$/i', $column, $matches)) {
        $prefix = $matches[1];  // e.g. 'rcr'
        $entity = $matches[2];  // e.g. 'run'
        return $prefix . '_' . self::pluralizeEntity($entity);
    }
    return null;
}
```

`pluralizeEntity()` (~lines 189-214) has an irregular-word list
(`address`/`category`/`entry`/`query`) and otherwise does `y → ies`,
`s` → `+es`, else `+s`. There is no lookup against any real table — it is
pure string transformation on the column name, run once per column at
schema-sync time (`registerModelRules()`, called from
`registerModelsFromDiscovery()`, called from `update_database.php`,
`PluginManager::activate()`/`sync()`, and
`PluginHelper::registerAllActiveDeletionRules()`).

Rules are consumed by `SystemBase::permanent_delete()` via
`SELECT * FROM del_deletion_rules WHERE del_source_table = ?` keyed on
`static::$tablename` of the row being deleted — hence the silent no-match
failure mode.

## Full accounting (audit of live `del_deletion_rules`, 2026-07-06)

Comparing every registered rule against the real `$tablename` of every loaded
core + plugin model: **100 of 197 rules reference a source or target table
that no model declares.** They fall into four classes:

### Class A — primary keys misdetected as foreign keys (~60 rules, harmless noise)

The 4-segment regex also matches primary-key columns
(`act_activation_code_id` → bogus source `activation_codes`,
`apk_api_key_id` → `api_keies`, `cht_change_tracking_id` → `change_trackings`,
dozens more). These were never foreign keys; the garbage rules never fire and
cascade nothing, but they prove the detector fires far beyond its intent and
they pollute the rules table.

### Class B — genuine FK columns whose guess misses the real table (8 columns, real dead cascades)

| Column (declaring model) | Guessed source | Real table | Consequence |
|---|---|---|---|
| `aip_rcr_run_id` (AipRecipeItemLog) | `rcr_runs` | `rcr_recipe_runs` | deleting a RecipeRun orphans its item-log rows |
| `aia_aim_message_id` (AiMessageAttachment) | `aim_messages` | `aim_conversation_messages` | worked around by hand-written cascade in `AiConversationMessage::permanent_delete()` |
| `evt_svy_survey_id` (Event) | `svy_surveies` | `svy_surveys` | pluralizer bug (`y → ies` on "survey") |
| `bkt_svy_survey_id` (BookingType) | `svy_surveies` | `svy_surveys` | same |
| `srq_svy_survey_id` (SurveyQuestion) | `svy_surveies` | `svy_surveys` | **deleting a survey orphans its questions** |
| `sva_svy_survey_id` (SurveyAnswer) | `svy_surveies` | `svy_surveys` | **deleting a survey orphans its answers** |
| `mgn_mgh_host_id` (ManagedNode) | `mgh_hosts` | `mgh_managed_hosts` | server_manager set_null rule dead |
| `mjb_mgn_node_id` (ManagementJob) | `mgn_nodes` | `mgn_managed_nodes` | server_manager set_null rule dead |

### Class C — role-named / suffixed FK columns that produce garbage or nothing

Columns like `rcp_owner_user_id`, `rcn_owner_user_id`, `aic_owner_user_id`
(guessed `owner_users`), `evt_parent_event_id` (guessed `parent_events`),
`cal_parent_entry_id`, `cnv_previous_version_id`, `ord_billing_address_id`,
`usa_zip_code_id`, and external-ID strings like `usr_stripe_customer_id`
(guessed `stripe_customers` — not an internal FK at all). Consequences vary:
the owner-column cases mean **deleting a user does not clean up their
recipes, notes, or AI conversations**; the stripe/zip cases are Class-A-style
noise on columns that should never register at all.

### Class D — `$foreign_key_actions` declarations the detector cannot see (32 keys across 25 model files, dead configuration)

The 4-segment gate rejects any longer or suffixed column, so declared
overrides like `ieg_iea_inbound_email_alias_id => cascade`,
`mlr_mlt_mailing_list_id`, `evt_usr_user_id_leader`,
`msg_usr_user_id_sender`, and essentially the **entire inbound_email
plugin's declared cascade configuration** are silently ignored.

### Rename fallout (2 stale rules)

Two tables were renamed on 2026-07-06 (`cal_entry_exceptions` →
`cex_entry_exceptions`, `sddb_device_backups` → `sbk_device_backups`); the
rules table still holds rows pointing at the old names, so the
calendar-entry→exception cascade and the user→device-backup cascade are dead
until re-registration. Nothing prunes stale rules today.

### Audit query (rerun to reproduce, and as the post-fix acceptance check)

Load all core + plugin models, build the set of real `$tablename` values,
then flag every `del_deletion_rules` row whose `del_source_table` or
`del_target_table` is not in the set. (One-liner PHP script; must report **0
broken** after the fix + re-sync + prune.)

## Interim protection already in place (implemented 2026-07-06)

`maintenance_scripts/dev_tools/validate_php_file.php`'s model-contract pass
now emits two **advisories** when validating a model file:

1. An FK column whose simulated auto-detector guess (same pluralization
   rules, mirrored in `pluralizeLikeDeletionDetector()`) does not match any
   real model's table — names the wrong guess and the real table.
2. A `$foreign_key_actions` key the 4-segment gate cannot see (dead
   configuration).

These warn model authors at edit time but do not fix registration. **Both
must be updated as part of this fix** (step 6 below) since their semantics
mirror the current detector.

## Fix plan (for the executor)

### 1. Replace the guess with an authoritative lookup

In `data/deletion_rule_class.php`, replace `getSourceTableFromColumn()`'s
regex-guess with a real `$prefix → $tablename` map:

- Build the map once via an **unfiltered** `LibraryFunctions::discover_model_classes()`
  call (`include_plugins => true`, require tablename), cached in a `static`
  local — mirror the exact pattern of `SystemBase::getModelClassForTable()`
  (`includes/SystemBase.php:886-902`). Do NOT reuse the (possibly
  plugin-filtered) class list `registerModelsFromDiscovery()` received: a
  foreign key may target a table in a *different* plugin.
- `pluralizeEntity()` becomes dead code — remove it.

### 2. Generalize FK-column recognition (replaces the 4-segment gate)

For each column in `field_specifications` **except `static::$pkey_column`**:
strip the declaring model's own `{prefix}_`; if the remainder's first
segment is a key in the prefix map and the remainder contains `_id`, the
column is an FK to that prefix's model — source table comes from the map.

This one rule simultaneously:
- fixes all 8 Class B mis-guesses (lookup, not pluralization),
- eliminates Class A entirely (`activation` etc. are not model prefixes; the
  pkey is also excluded explicitly),
- eliminates the Class C garbage (`owner`, `parent`, `stripe`, `zip`,
  `billing` are not model prefixes — these now register nothing instead of
  garbage),
- makes most of Class D auto-register correctly
  (`ieg_iea_inbound_email_alias_id` → `iea` → real table;
  `evt_usr_user_id_leader` → `usr` → `usr_users`).

When the first segment is NOT a known prefix, register nothing (never
guess). Unregistered-but-declared is the accepted outcome; registered-wrong
is not.

### 3. Explicit escape hatch for unresolvable `$foreign_key_actions` keys

Extend the `$foreign_key_actions` value schema with an optional
`'source_table' => 'usr_users'` (or `'source_class' => 'User'`) key, used
verbatim by the registrar when the column shape can't resolve by convention.
Then declare it where needed — at minimum audit these known unresolvable
keys and either add `source_table`, fix the key, or remove dead entries:
`ntf_source_usr_user_id`, `mjb_created_by`, `agf_candidate_for`,
`grp_usr_user_id_created` (resolves via `usr` — verify),
`adm_adm_admin_menu_id_parent` (the `adm_` prefix looks wrong — AdminMenu's
prefix is `amu`; investigate before keeping). The owner-column cases
(`rcp_owner_user_id`, `rcn_owner_user_id`, `aic_owner_user_id`) should get
explicit `source_table => 'usr_users'` cascades — deleting a user must clean
up their recipes, notes, and AI conversations.

During registration, when a `$foreign_key_actions` key neither resolves by
convention nor declares `source_table`, emit a warning into the sync results
(never silently ignore a declared override).

### 4. Make registration idempotent and self-pruning

- `registerModelRules()` for a model should replace that target table's rules
  atomically: delete existing `del_deletion_rules` rows
  `WHERE del_target_table = {tablename}`, then insert the fresh set.
- Add a cleanup pass at the end of the full (unfiltered) registration run:
  delete any rule whose `del_source_table` OR `del_target_table` matches no
  loaded model's `$tablename`. This purges all ~100 broken rows including the
  two rename-fallout rules. (Platform is pre-launch — aggressive pruning is
  authorized; this spec is the authorization for the required DB writes.)

### 5. Re-register everything — and review every NEW rule before accepting

Run `update_database` (admin utilities or CLI) and sync every active plugin
so the full rule set regenerates under the new logic. Then re-run the audit
query — it must report 0 broken rules.

**Safety gate:** diff the full before/after rule set and explicitly review
every rule that is NEW (did not exist, correctly, before). Pay particular
attention to newly recognized columns that registered with the **default
cascade** action without an explicit `$foreign_key_actions` declaration —
the widened recognition makes previously-invisible FK columns register, and
a default cascade nobody declared is the one place this fix could delete
more than intended. For each such rule, either confirm cascade is the right
action or add an explicit declaration (`null` / `set_value` / `prevent`).
List every new rule and its disposition in the implementation notes.

### 6. Update the validator to match the fixed detector

In `maintenance_scripts/dev_tools/validate_php_file.php` (model-contract
pass):
- Remove `pluralizeLikeDeletionDetector()` and the guess-simulation advisory
  (the wrong-guess failure mode no longer exists).
- Rework the dead-`$foreign_key_actions` advisory to the new semantics: flag
  only keys that neither resolve by convention (first post-prefix segment is
  a known model prefix) nor declare `source_table`.
- Update `maintenance_scripts/dev_tools/validate_php_file_README.md`'s two
  advisory bullets to match.

### 7. Clean up the historical workarounds' comments (code can stay)

- `AiConversationMessage::permanent_delete()`'s hand-written attachment
  cascade is correct and stays; rewrite its doc comment to current-state
  (it currently narrates the old guesser's failure).
- `inbound_email_mailbox_grant_class.php`'s header comment likewise —
  after the fix its alias→grant cascade auto-registers, and the read-layer
  guard (`MailboxViewer::accessibleAliasIds()`) becomes belt-and-suspenders;
  say that, without narrating the old limitation.

### 8. Documentation

Update `docs/deletion_system.md` to describe the final behavior only (per
project doc rules: no "previously", no migration narrative): FK recognition
by prefix lookup, the `source_table` escape hatch, the sync-time
warning for unresolvable declared keys, idempotent re-registration, and the
orphan-rule prune.

## Test plan

1. **Unit:** source-table resolution returns `rcr_recipe_runs` for
   `aip_rcr_run_id`, `svy_surveys` for `evt_svy_survey_id`,
   `iea_inbound_email_aliases`-equivalent for the six-segment
   `ieg_iea_inbound_email_alias_id` (use the real alias table name), and
   `usr_users` for `evt_usr_user_id_leader`.
2. **Unit:** a column whose post-prefix first segment is not a registered
   model prefix returns null (no rule), including pkey shapes
   (`act_activation_code_id`) and role names (`rcp_owner_user_id` absent an
   explicit `source_table`).
3. **Unit:** `$foreign_key_actions` with explicit `source_table` registers
   against exactly that table; without it and unresolvable, a sync warning
   is emitted and nothing registers.
4. **Integration:** sync `joinery_ai`; permanently delete a `RecipeRun` with
   `aip_recipe_item_log` rows → log rows gone.
5. **Integration:** permanently delete a Survey with questions and answers →
   both gone (dead `svy_surveies` rules replaced).
6. **Integration:** permanently delete a CalendarEntry with a
   `cex_entry_exceptions` row → exception gone (rename fallout healed).
7. **Regression:** full `update_database` + all-plugin sync twice in a row —
   second run is a no-op (idempotency); every previously-correct rule still
   registers identically (diff before/after `del_deletion_rules` on the
   ~97 rules that were valid).
8. **Acceptance:** the audit query reports **0** rules whose source or
   target table matches no loaded model.
9. **Validator:** all model files still pass
   `validate_php_file.php` (exit 0 aside from the three known unrelated
   broken-call files: `address_class.php`, `conversations_class.php`,
   `emails_class.php`); the reworked advisory fires on a fixture model whose
   `$foreign_key_actions` key is unresolvable and lacks `source_table`.

## Completion

Move this file to `specs/implemented/` when all acceptance criteria pass.
