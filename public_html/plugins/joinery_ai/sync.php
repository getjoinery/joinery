<?php
/**
 * Joinery AI sync hook — runs on every plugin sync and at activation.
 *
 * Two jobs, both idempotent:
 *
 *  1. Seed the recipes declared in recipes.json. Create-only: after the first
 *     run it does nothing until a new declaration appears.
 *     See specs/implemented/joinery_ai_shipped_recipes.md.
 *  2. Clear plaintext left in run rows by runs that read protected mail before
 *     run rows could seal. See specs/sealed_content_egress.md § decision 2.
 *
 * Why (2) lives here and not in a core migration: it reads and writes columns
 * on rcr_recipe_runs, a PLUGIN table. Core migrations run several hundred lines
 * before PluginManager::sync() creates or alters plugin tables, so a migration
 * touching rcr_content_sealed runs against a table that does not have it yet.
 * The plugin sync hook runs after that step, which is the only place the
 * columns are guaranteed to exist.
 *
 * @return string[] messages for the sync report
 */
function joinery_ai_sync() {
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeSeeder.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RunContentPurge.php'));

    $messages = RecipeSeeder::seedDeclared();

    foreach (RunContentPurge::run() as $message) {
        $messages[] = $message;
    }

    return $messages;
}
