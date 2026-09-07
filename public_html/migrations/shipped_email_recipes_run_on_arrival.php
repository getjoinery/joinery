<?php
/**
 * The shipped email recipes run as mail arrives.
 *
 * recipes.json is create-only, so a declaration change never reaches a
 * deployment that already seeded the row. The three mail templates shipped on
 * an hourly clock, which means someone who turns one on waits up to an hour
 * before anything happens and then sees a batch — the wrong shape for work
 * whose items arrive one at a time. Rows still carrying the old factory
 * 'hourly' move to 'arrival'; anything an operator already changed is left
 * alone, and enablement is untouched (a seeded template stays inert until
 * someone turns it on).
 *
 * Idempotent: re-running finds no shipped row still at 'hourly'.
 *
 * The recipes table belongs to the joinery_ai plugin, so it is checked for
 * before it is touched, and so is the rcp_declared_key column: a site that
 * activated the plugin once and turned it off keeps the table but stops
 * receiving its columns (plugin tables sync for active plugins only), and a
 * row with no declared key is not a shipped row. Where either is absent there
 * is nothing to move: the rows are seeded from recipes.json on activation,
 * and that declaration already says 'arrival'.
 */
function shipped_email_recipes_run_on_arrival() {
    $db = DbConnector::get_instance()->get_db_link();

    $q = $db->prepare("SELECT to_regclass('rcp_recipes')");
    $q->execute();
    if ($q->fetchColumn() === null) {
        echo "  shipped email recipes: no recipes table here (joinery_ai not active), nothing to move\n";
        return;
    }
    $q = $db->prepare(
        "SELECT count(1) FROM information_schema.columns
          WHERE table_name = 'rcp_recipes' AND column_name = 'rcp_declared_key'");
    $q->execute();
    if ((int)$q->fetchColumn() === 0) {
        echo "  shipped email recipes: recipes table carries no declared key here (joinery_ai inactive), nothing to move\n";
        return;
    }

    $q = $db->prepare(
        "UPDATE rcp_recipes SET rcp_schedule_frequency = 'arrival'
          WHERE rcp_declared_key IN ('email_triage_default',
                                     'email_security_scan_default',
                                     'email_schedule_default')
            AND rcp_schedule_frequency = 'hourly'");
    $q->execute();
    echo $q->rowCount() > 0
        ? "  shipped email recipes moved to 'as mail arrives': " . $q->rowCount() . "\n"
        : "  shipped email recipes: none still on the old hourly default\n";
}
?>
