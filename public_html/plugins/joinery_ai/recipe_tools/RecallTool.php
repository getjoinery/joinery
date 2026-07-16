<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_memories_class.php'));

/**
 * Read stored memories: the acting user's own + the org's shared pool
 * (specs/joinery_ai_memory.md). Two ways in, matching how the always-present
 * memory index is used: by ids (pull the full body of a memory whose title
 * the index showed) or by keyword query (ILIKE across title+content).
 *
 * Requested ids are filtered through the same scope, never fetched blind —
 * a guessed id for another user's memory returns nothing and leaks nothing
 * (no "exists but forbidden" signal).
 *
 * The whole payload is wrapped in the untrusted-content envelope
 * ($ctx->untrustedNonce(), the same wrap get_workspace / view_attachment
 * apply), so a stored memory's text can never be read back as an instruction.
 */
class RecallTool implements RecipeToolInterface {

    const DEFAULT_LIMIT = 10;
    const MAX_LIMIT = 25;

    public static function name(): string {
        return 'recall';
    }

    public static function description(): string {
        return 'Read stored memories in full — your saved memories for this user plus '
             . 'the shared organization memories. Fetch specific entries by id (from '
             . 'the memory index in your context) or search by keyword across titles '
             . 'and content. Provide ids, a query, or both.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Keywords to match against memory titles and content (case-insensitive).',
                ],
                'ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Specific memory ids to fetch in full (e.g. from the memory index).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many memories to return (1-25, default 10).',
                    'minimum' => 1,
                    'maximum' => 25,
                ],
                'scope' => [
                    'type' => 'string',
                    'enum' => ['all', 'mine', 'shared'],
                    'description' => 'Narrow to the user\'s own memories or the shared pool (default all).',
                ],
            ],
        ];
    }

    public function execute(array $input, ToolContext $ctx) {
        $query = trim((string)($input['query'] ?? ''));
        $ids = is_array($input['ids'] ?? null) ? $input['ids'] : [];
        if ($query === '' && empty($ids)) {
            return ['content' => 'Provide a query (keywords) or ids (from the memory index) — recall never dumps the whole store.', 'is_error' => true];
        }
        $limit = (int)($input['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit < 1) $limit = 1;
        if ($limit > self::MAX_LIMIT) $limit = self::MAX_LIMIT;
        $scope = (string)($input['scope'] ?? 'all');
        if (!in_array($scope, ['all', 'mine', 'shared'], true)) $scope = 'all';

        $rows = MultiAiMemory::recallRows($ctx->actingUserId(), $query, $ids, $limit, $scope);
        if (!$rows) {
            return $query !== ''
                ? "No memories match '$query'."
                : 'No matching memories found.';
        }

        $tz = $ctx->ownerTimezone();
        $lines = [count($rows) === 1 ? '1 memory:' : count($rows) . ' memories:', ''];
        foreach ($rows as $r) {
            // Collapse whitespace so a title with an embedded newline can't
            // smear the heading (same guard search_conversations applies).
            $title = trim((string)preg_replace('/\s+/', ' ', (string)$r['mem_title']));
            if ($title === '') $title = '(untitled)';

            $meta = 'id ' . (int)$r['mem_memory_id'];
            $meta .= ' · ' . ($r['mem_scope'] === AiMemory::SCOPE_SHARED ? 'shared' : 'personal');
            $meta .= ' · saved by ' . (string)$r['mem_source'];
            $when = $r['mem_update_time'] ?: $r['mem_create_time'];
            if ($when) {
                $meta .= ' · ' . LibraryFunctions::convert_time($when, 'UTC', $tz, 'M j, Y');
            }
            $tags = json_decode((string)$r['mem_tags'], true);
            if (is_array($tags) && count($tags)) {
                $meta .= ' · [' . implode(', ', array_map('strval', $tags)) . ']';
            }

            $lines[] = "## $title";
            $lines[] = '_' . $meta . '_';
            $lines[] = '';
            $lines[] = (string)$r['mem_content'];
            $lines[] = '';
        }

        // Whole-payload untrusted wrap: memory content is stored text, data only.
        $nonce = $ctx->untrustedNonce();
        return "<<UNTRUSTED_$nonce>>" . trim(implode("\n", $lines)) . "<</UNTRUSTED_$nonce>>";
    }

}
