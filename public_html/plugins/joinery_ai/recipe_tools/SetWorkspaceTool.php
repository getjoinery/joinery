<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));

/**
 * Overwrite this recipe's persistent workspace.
 *
 * Hard-capped at joinery_ai_workspace_max_chars (default 8000). Oversize writes
 * fail with is_error so the LLM sees a recoverable error and can compact its
 * own notes before retrying. The cap protects unbounded prompt growth across
 * runs — without it the workspace would silently inflate token usage.
 *
 * The runner persists rcp_workspace as part of finishSuccess. Saving here on
 * each call would cost an extra UPDATE per write; instead we just mutate the
 * in-memory Recipe — the runner picks it up at end of run.
 */
class SetWorkspaceTool implements RecipeToolInterface {

    public static function name(): string {
        return 'set_workspace';
    }

    public static function description(): string {
        return 'Overwrite the recipe\'s persistent workspace with new content. '
             . 'Treat this as a curated rolling note: when approaching the size '
             . 'cap (8000 chars by default), summarize/compact older content '
             . 'rather than appending forever. Returns an error if the new '
             . 'content exceeds the cap — in that case rewrite more concisely '
             . 'and try again.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'content' => [
                    'type' => 'string',
                    'description' => 'The new workspace contents. Replaces any prior workspace entirely.',
                ],
            ],
            'required' => ['content'],
        ];
    }

    public function execute(array $input, RecipeRunContext $ctx) {
        if (!array_key_exists('content', $input)) {
            return ['content' => 'set_workspace error: missing required field "content".', 'is_error' => true];
        }
        $content = (string)$input['content'];

        $settings = Globalvars::get_instance();
        $cap = (int)($settings->get_setting('joinery_ai_workspace_max_chars') ?: 8000);
        if ($cap < 100) $cap = 8000;  // sanity floor

        $len = mb_strlen($content);
        if ($len > $cap) {
            return [
                'content' => "set_workspace rejected: content is $len characters, "
                           . "but the workspace cap is $cap. Rewrite more concisely "
                           . "(summarize older entries, drop low-value lines) and try again.",
                'is_error' => true,
            ];
        }

        $ctx->recipe->set('rcp_workspace', $content);
        return "Workspace updated ($len / $cap chars).";
    }

}
