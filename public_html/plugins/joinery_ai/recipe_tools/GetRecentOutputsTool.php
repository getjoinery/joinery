<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));

/**
 * Read the most-recent successful outputs of another (or this) recipe.
 *
 * Lets recipes read each other — a "weekly digest of digests" pattern, or a
 * stock-research recipe checking what the news-digest recipe surfaced. Scoped
 * to the current recipe's owner so cross-user leakage is impossible.
 */
class GetRecentOutputsTool implements RecipeToolInterface {

    const MAX_LIMIT = 10;
    const MAX_OUTPUT_CHARS_PER_RUN = 4000;

    public static function name(): string {
        return 'get_recent_outputs';
    }

    public static function description(): string {
        return 'Read the most-recent successful outputs of a named recipe (or '
             . 'this recipe). Use this to chain recipes, build "digest of '
             . 'digests" workflows, or grade prior predictions you logged. '
             . 'Output of each run is truncated to keep tokens manageable.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_name' => [
                    'type' => 'string',
                    'description' => 'Recipe name to read. Omit or empty string to read this recipe\'s own prior runs.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many recent runs to return (1-10, default 3).',
                    'minimum' => 1,
                    'maximum' => 10,
                ],
            ],
        ];
    }

    public function execute(array $input, RecipeRunContext $ctx) {
        $name = trim((string)($input['recipe_name'] ?? ''));
        $limit = (int)($input['limit'] ?? 3);
        if ($limit < 1) $limit = 1;
        if ($limit > self::MAX_LIMIT) $limit = self::MAX_LIMIT;

        // Resolve target recipe.
        if ($name === '') {
            $target_recipe = $ctx->recipe;
        } else {
            $matches = new MultiRecipe(['name' => $name, 'owner_user_id' => $ctx->owner_user_id]);
            $matches->load();
            if (!count($matches)) {
                return [
                    'content' => "get_recent_outputs error: no recipe named '$name' found for this owner.",
                    'is_error' => true,
                ];
            }
            $target_recipe = $matches->get(0);
        }

        // Pull recent successful runs.
        $runs = new MultiRecipeRun(
            ['recipe_id' => (int)$target_recipe->key, 'status' => RecipeRun::STATUS_SUCCESS],
            ['rcr_started_time' => 'DESC'],
            $limit
        );
        $runs->load();

        if (!count($runs)) {
            return "No successful runs found for recipe '" . $target_recipe->get('rcp_name') . "'.";
        }

        $session = SessionControl::get_instance();
        $tz = $ctx->owner_timezone;

        $lines = ["Recent runs of recipe '" . $target_recipe->get('rcp_name') . "':", ''];
        foreach ($runs as $i => $run) {
            $when = LibraryFunctions::convert_time(
                $run->get('rcr_started_time'), 'UTC', $tz, 'M j, Y g:i A T'
            );
            $output = (string)$run->get('rcr_output');
            if (mb_strlen($output) > self::MAX_OUTPUT_CHARS_PER_RUN) {
                $output = mb_substr($output, 0, self::MAX_OUTPUT_CHARS_PER_RUN) . "\n…(truncated)";
            }
            $lines[] = '--- Run #' . (int)$run->key . ' (' . $when . ') ---';
            $lines[] = $output;
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

}
