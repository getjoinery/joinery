<?php
/**
 * Email recipe bindings: one mailbox becomes a list
 * (specs/implemented/ai_recipes_multi_mailbox_and_ai_panel.md § Phase 1).
 *
 * rcp_source_config for the email pipeline jobs carries `mailbox_aliases`
 * (a list of addresses); the code reads only that shape, with no runtime
 * compat shim. Any stored config still carrying the legacy single
 * `mailbox_alias` key is rewritten to a one-element list.
 *
 * Idempotent: a rewritten row no longer has the legacy key.
 */
function recipe_mailbox_alias_to_list() {
    $db = DbConnector::get_instance()->get_db_link();

    // rcp_recipes belongs to the joinery_ai plugin, so its schema is only as
    // current as that plugin. An installed-but-inactive plugin does not get its
    // tables synced, which leaves the table sitting at whatever shape it had
    // when the plugin was last active — on getjoinery.com, without this column
    // at all. A core migration cannot assume a plugin's columns exist; without
    // this check the statement below fails and rolls back the entire upgrade
    // over a table holding nothing to migrate.
    $has_column = (int)$db->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = 'public'
            AND table_name = 'rcp_recipes'
            AND column_name = 'rcp_source_config'")->fetchColumn();
    if (!$has_column) {
        echo "  rcp_recipes: no rcp_source_config column (joinery_ai inactive or at an older schema) — nothing to rewrite\n";
        return;
    }

    // jsonb_exists() rather than the ? operator — PDO would read the bare ?
    // as a positional placeholder.
    $q = $db->prepare(
        "UPDATE rcp_recipes
            SET rcp_source_config =
                (rcp_source_config - 'mailbox_alias')
                || jsonb_build_object('mailbox_aliases',
                       jsonb_build_array(rcp_source_config->'mailbox_alias'))
          WHERE jsonb_exists(rcp_source_config, 'mailbox_alias')");
    $q->execute();
    echo $q->rowCount() > 0
        ? '  rcp_recipes: rewrote ' . $q->rowCount() . " legacy mailbox_alias binding(s) to mailbox_aliases lists\n"
        : "  rcp_recipes: no legacy mailbox_alias bindings (nothing to rewrite)\n";
}
?>
