<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_memories_class.php'));

/**
 * Soft-delete one of the acting user's OWN memories (specs/joinery_ai_memory.md).
 *
 * Never a shared row, never another user's: an id outside the caller's own
 * user-scope rows (shared, foreign, already-deleted, or nonexistent) is a
 * no-op with the same neutral message — no existence signal leaks, matching
 * recall's non-leaking posture on ids.
 */
class ForgetTool implements RecipeToolInterface {

    public static function name(): string {
        return 'forget';
    }

    public static function description(): string {
        return 'Delete one of the user\'s own stored memories by id (from the memory '
             . 'index or a recall result). Use when the user asks you to forget '
             . 'something, or when a stored memory is wrong or obsolete. Shared '
             . 'organization memories cannot be deleted this way.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'memory_id' => [
                    'type' => 'integer',
                    'description' => 'The id of the memory to delete.',
                ],
            ],
            'required' => ['memory_id'],
        ];
    }

    public function execute(array $input, ToolContext $ctx) {
        $id = (int)($input['memory_id'] ?? 0);
        $neutral = "No memory with id $id was found among the user's own memories — nothing was deleted.";
        if ($id <= 0) return $neutral;

        $memory = new AiMemory($id, TRUE);
        if (!$memory->key
                || (string)$memory->get('mem_scope') !== AiMemory::SCOPE_USER
                || (int)$memory->get('mem_owner_user_id') !== $ctx->actingUserId()
                || $memory->get('mem_delete_time')) {
            return $neutral;
        }

        $memory->soft_delete();
        return "Forgot memory id $id.";
    }

}
