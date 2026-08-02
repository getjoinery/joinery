<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeVaultScope.php'));

/**
 * Clear the content columns on runs that read protected mail before run rows
 * could seal (specs/implemented/sealed_content_egress.md § resolved decision 2).
 *
 * A run against a protected mailbox copied what it read into columns with no
 * encryption of any kind: the tally in rcr_output, the per-item trace in
 * rcr_tool_calls (item label = the mail subject, verdict = a description of the
 * body), the agent workspace, and provider errors that can echo either. Those
 * copies are readable by anyone who can read the database, with no unlock
 * window, which is exactly what the mailbox's protection level promises they
 * are not.
 *
 * Only the content is cleared. Counts, timings, tokens, cost and status stay:
 * they describe the run, not its source, and run history is built from them.
 *
 * Scope is decided by the same predicate the runner uses to seal a new run —
 * RecipeVaultScope::forRecipe() — so a recipe this skips is exactly a recipe
 * whose future runs will not seal.
 *
 * Runs from the plugin sync hook rather than a core migration, because core
 * migrations execute before plugin tables are synced and the sealing columns
 * would not exist yet. Idempotent: a second pass finds the columns already
 * empty and clears nothing, which is what makes running on every sync fine.
 *
 * @version 1.0
 */
class RunContentPurge {

    /** @return string[] messages for the sync report */
    public static function run(): array {
        $tables = LibraryFunctions::get_tables_and_columns();
        if (!isset($tables['rcr_recipe_runs']) || !isset($tables['rcp_recipes'])) {
            return [];
        }
        // The guard the core-migration placement could not give: only proceed
        // once the sealing column actually exists. get_tables_and_columns()
        // returns a plain list of column names per table.
        if (!in_array('rcr_content_sealed', (array)$tables['rcr_recipe_runs'], true)) {
            return [];
        }

        $dblink = DbConnector::get_instance()->get_db_link();

        try {
            $recipe_ids = $dblink->query(
                'SELECT rcp_recipe_id FROM rcp_recipes ORDER BY rcp_recipe_id'
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            error_log('[joinery_ai] run-content purge could not list recipes: ' . $e->getMessage());
            return [];
        }

        $sealed_sources = [];
        foreach ($recipe_ids as $id) {
            try {
                $recipe = new Recipe((int)$id, TRUE);
                if (!$recipe->key) continue;
                // scopeOrThrow, not forRecipe: forRecipe swallows a failed scope
                // check into "no scope", which here would mean KEEPING plaintext
                // for a recipe nobody could prove clean. The catch below turns a
                // throw into the clearing direction instead.
                if (RecipeVaultScope::scopeOrThrow($recipe) !== null) {
                    $sealed_sources[] = (int)$id;
                }
            } catch (Throwable $e) {
                // A recipe whose source no longer resolves cannot be proven
                // safe, so treat it as protected and clear it.
                $sealed_sources[] = (int)$id;
            }
        }

        if (empty($sealed_sources)) return [];

        $placeholders = implode(',', array_fill(0, count($sealed_sources), '?'));
        try {
            $stmt = $dblink->prepare(
                "UPDATE rcr_recipe_runs
                    SET rcr_output = NULL, rcr_tool_calls = NULL, rcr_error = NULL,
                        rcr_workspace_before = NULL, rcr_workspace_after = NULL
                  WHERE rcr_rcp_recipe_id IN ({$placeholders})
                    AND rcr_content_sealed IS NOT TRUE
                    AND (rcr_output IS NOT NULL OR rcr_tool_calls IS NOT NULL
                         OR rcr_error IS NOT NULL OR rcr_workspace_before IS NOT NULL
                         OR rcr_workspace_after IS NOT NULL)"
            );
            $stmt->execute($sealed_sources);
        } catch (Throwable $e) {
            error_log('[joinery_ai] run-content purge failed: ' . $e->getMessage());
            return ['run-content purge failed — see the error log; no rows were changed.'];
        }

        $cleared = $stmt->rowCount();
        if ($cleared === 0) return [];

        return ["cleared plaintext content on {$cleared} run row(s) that read protected mail; "
              . 'counts, timings and status are unchanged.'];
    }
}
