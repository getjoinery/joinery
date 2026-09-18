<?php
/**
 * The email triage recipe is called "Email summaries".
 *
 * The job writes a one-line summary per message and nothing else, and its
 * name says so. recipes.json is create-only, so the renamed declaration never
 * reaches a deployment that already seeded the row, and a member's own
 * instance (made from the panel's toggle) carried the declaration's name at
 * the moment it was made. Every email_triage recipe still carrying the
 * factory name takes the new one; a name an operator chose is left alone.
 *
 * Idempotent: re-running finds no email_triage row still named "Email triage".
 *
 * The recipes table belongs to the joinery_ai plugin, so it is checked for
 * before it is touched: a site that never activated the plugin has no table,
 * and seeds the renamed declaration on activation.
 */
function email_triage_recipes_named_email_summaries() {
    $db = DbConnector::get_instance()->get_db_link();

    $q = $db->prepare("SELECT to_regclass('rcp_recipes')");
    $q->execute();
    if ($q->fetchColumn() === null) {
        echo "  email summaries: no recipes table here (joinery_ai not active), nothing to rename\n";
        return;
    }

    $q = $db->prepare(
        "UPDATE rcp_recipes SET rcp_name = 'Email summaries'
          WHERE rcp_pipeline_job = 'email_triage'
            AND rcp_name = 'Email triage'");
    $q->execute();
    echo $q->rowCount() > 0
        ? "  email triage recipes renamed 'Email summaries': " . $q->rowCount() . "\n"
        : "  email summaries: no recipe still carries the old name\n";
}
?>
