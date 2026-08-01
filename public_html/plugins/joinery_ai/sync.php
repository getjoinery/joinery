<?php
/**
 * Joinery AI sync hook — runs on every plugin sync and at activation.
 *
 * Seeds the recipes declared in recipes.json onto this install. Create-only and
 * idempotent: after the first run it does nothing until a new declaration
 * appears. See specs/implemented/joinery_ai_shipped_recipes.md.
 *
 * @return string[] messages for the sync report
 */
function joinery_ai_sync() {
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeSeeder.php'));
    return RecipeSeeder::seedDeclared();
}
