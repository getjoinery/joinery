<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_notes_class.php'));

/**
 * Read notes the owner has saved, optionally filtered by an ILIKE search.
 *
 * Pair with save_note to form the agent ↔ human feedback loop: agent writes
 * a note, human edits it in the admin UI, next run reads back via this tool
 * and acts on the edit.
 *
 * Search uses raw PG ILIKE rather than going through MultiRecipeNote — the
 * search filter isn't a clean fit for the SystemMultiBase pattern (it needs
 * an OR across two columns with the same parameter), so a direct PDO query
 * is simpler than fighting the framework. Owner-scoping is still strict.
 */
class GetMyNotesTool implements RecipeToolInterface {

    const MAX_LIMIT = 20;
    const DEFAULT_LIMIT = 10;
    const MAX_CONTENT_PREVIEW = 1000;

    public static function name(): string {
        return 'get_my_notes';
    }

    public static function description(): string {
        return 'Read the owner\'s notes (saved earlier by save_note or written '
             . 'manually in the admin UI). Optionally filter with a search '
             . 'string that matches title or content (case-insensitive). '
             . 'Returns the most-recently-modified notes first, with content '
             . 'truncated for token efficiency.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'search' => [
                    'type' => 'string',
                    'description' => 'Optional substring to match against title and content. Empty string returns all notes.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many notes to return (1-20, default 10).',
                    'minimum' => 1,
                    'maximum' => 20,
                ],
            ],
        ];
    }

    public function execute(array $input, RecipeRunContext $ctx) {
        $search = trim((string)($input['search'] ?? ''));
        $limit = (int)($input['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit < 1) $limit = 1;
        if ($limit > self::MAX_LIMIT) $limit = self::MAX_LIMIT;

        $db = DbConnector::get_instance()->get_db_link();

        if ($search === '') {
            $sql = "SELECT rcn_note_id, rcn_title, rcn_content, rcn_tags, rcn_update_time
                    FROM rcn_notes
                    WHERE rcn_owner_user_id = ? AND rcn_delete_time IS NULL
                    ORDER BY COALESCE(rcn_update_time, rcn_create_time) DESC
                    LIMIT ?";
            $q = $db->prepare($sql);
            $q->execute([$ctx->owner_user_id, $limit]);
        } else {
            $like = '%' . $search . '%';
            $sql = "SELECT rcn_note_id, rcn_title, rcn_content, rcn_tags, rcn_update_time
                    FROM rcn_notes
                    WHERE rcn_owner_user_id = ?
                      AND rcn_delete_time IS NULL
                      AND (rcn_title ILIKE ? OR rcn_content ILIKE ?)
                    ORDER BY COALESCE(rcn_update_time, rcn_create_time) DESC
                    LIMIT ?";
            $q = $db->prepare($sql);
            $q->execute([$ctx->owner_user_id, $like, $like, $limit]);
        }

        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return $search === ''
                ? 'You have no notes yet.'
                : "No notes match '$search'.";
        }

        $tz = $ctx->owner_timezone;
        $lines = [count($rows) === 1 ? '1 note:' : count($rows) . ' notes:', ''];
        foreach ($rows as $r) {
            $title = $r['rcn_title'];
            $content = (string)$r['rcn_content'];
            if (mb_strlen($content) > self::MAX_CONTENT_PREVIEW) {
                $content = mb_substr($content, 0, self::MAX_CONTENT_PREVIEW) . "\n…(truncated)";
            }
            $tags = '';
            if (!empty($r['rcn_tags'])) {
                $decoded = json_decode($r['rcn_tags'], true);
                if (is_array($decoded) && count($decoded)) {
                    $tags = ' [' . implode(', ', $decoded) . ']';
                }
            }
            $when = $r['rcn_update_time']
                ? LibraryFunctions::convert_time($r['rcn_update_time'], 'UTC', $tz, 'M j, Y g:i A')
                : '';

            $lines[] = "## $title" . $tags;
            if ($when) $lines[] = "_(updated $when)_";
            $lines[] = '';
            $lines[] = $content;
            $lines[] = '';
        }
        return trim(implode("\n", $lines));
    }

}
