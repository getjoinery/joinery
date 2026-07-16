<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_memories_class.php'));

/**
 * Store one durable memory for the acting user (specs/joinery_ai_memory.md).
 *
 * Always inserts (memories accumulate — each fact is its own row; no upsert,
 * unlike save_note's title-upsert scratchpad). The scope is hard-coded:
 * scope='user', owner=actingUserId, source='ai'. The AI can NEVER write a
 * shared memory — the org-wide pool is admin-curated only, which is both an
 * authority boundary and a prompt-injection defense (a poisoned memory can
 * only ever influence the one user whose chat wrote it).
 */
class RememberTool implements RecipeToolInterface {

    public static function name(): string {
        return 'remember';
    }

    public static function description(): string {
        return 'Save a durable memory — a fact worth recalling in future, separate '
             . 'conversations (a preference, a decision, a standing constraint). '
             . 'Stored in the user\'s own private memory. Use when the user asks you '
             . 'to remember something, or when a fact will clearly matter later. '
             . 'Each call stores a new memory; it never overwrites an existing one '
             . '(use forget to remove one).';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'content' => [
                    'type' => 'string',
                    'description' => 'The fact to remember, stated so it makes sense on its own later.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Optional short label (max 255 chars) — shown in the memory index, so make it descriptive.',
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional list of tags.',
                ],
            ],
            'required' => ['content'],
        ];
    }

    public function execute(array $input, ToolContext $ctx) {
        $content = (string)($input['content'] ?? '');
        $title = trim((string)($input['title'] ?? ''));
        $tags = $input['tags'] ?? null;

        if (trim($content) === '') {
            return ['content' => 'remember error: content is required and cannot be empty.', 'is_error' => true];
        }
        if (mb_strlen($content) > AiMemory::MAX_CONTENT_CHARS) {
            return ['content' => 'remember error: content exceeds ' . AiMemory::MAX_CONTENT_CHARS . ' chars.', 'is_error' => true];
        }
        if (mb_strlen($title) > AiMemory::MAX_TITLE_LEN) {
            return ['content' => 'remember error: title exceeds ' . AiMemory::MAX_TITLE_LEN . ' chars.', 'is_error' => true];
        }
        if ($tags !== null && !is_array($tags)) {
            return ['content' => 'remember error: tags must be an array of strings.', 'is_error' => true];
        }

        $memory = new AiMemory(NULL);
        $memory->set('mem_scope', AiMemory::SCOPE_USER);
        $memory->set('mem_owner_user_id', $ctx->actingUserId());
        $memory->set('mem_created_by_user_id', $ctx->actingUserId());
        $memory->set('mem_source', AiMemory::SOURCE_AI);
        $memory->set('mem_title', $title);
        $memory->set('mem_content', $content);
        if ($tags !== null) {
            $memory->set('mem_tags', array_values(array_map('strval', $tags)));
        }
        $memory->prepare();
        $memory->save();

        $label = $title !== '' ? $title : trim(strtok($content, "\n"));
        if (mb_strlen($label) > 80) $label = mb_substr($label, 0, 80) . '…';
        return "Remembered: '$label' (id " . (int)$memory->key . ").";
    }

}
