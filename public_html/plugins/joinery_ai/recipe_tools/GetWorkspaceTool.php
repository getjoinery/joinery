<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));

/**
 * Read this recipe's persistent workspace blob.
 *
 * The workspace is a free-form text scratchpad the LLM curates across runs —
 * not vector-stored, not RAG, just bytes. Pair with set_workspace to update.
 */
class GetWorkspaceTool implements RecipeToolInterface {

    public static function name(): string {
        return 'get_workspace';
    }

    public static function description(): string {
        return 'Read this recipe\'s persistent workspace — a text scratchpad '
             . 'that survives between runs. Use it to remember what you\'ve '
             . 'already done, what you\'ve already shown the user, and any '
             . 'state worth carrying forward (e.g. "artists already covered", '
             . '"stocks already analyzed this quarter"). Returns the empty '
             . 'string if nothing has been written yet.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => new stdClass(),  // empty {} in JSON
        ];
    }

    public function execute(array $input, RecipeRunContext $ctx) {
        $value = (string)$ctx->recipe->get('rcp_workspace');
        if ($value === '') {
            return '(workspace is empty)';
        }
        return $value;
    }

}
