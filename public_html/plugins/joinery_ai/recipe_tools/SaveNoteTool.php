<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_notes_class.php'));

/**
 * Write or update a note in the owner's notes table.
 *
 * Upsert by (owner_user_id, title): existing note with the same title is
 * updated; otherwise a new row is inserted. The notes are visible to the
 * owner in the admin UI (eventually — Phase 9 polish), so the agent can
 * write notes that the human edits between runs.
 */
class SaveNoteTool implements RecipeToolInterface {

    const MAX_TITLE_LEN = 255;
    const MAX_CONTENT_CHARS = 50000;

    public static function name(): string {
        return 'save_note';
    }

    public static function description(): string {
        return 'Save a note to the owner\'s notes (visible in the admin UI). '
             . 'If a note with the same title already exists, it is updated; '
             . 'otherwise a new note is created. Notes form a feedback loop: '
             . 'the agent writes, the human edits, the next run reads back '
             . 'with get_my_notes.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Short title (max 255 chars). Used as the upsert key — same title = update.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Note body, Markdown.',
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional list of tags.',
                ],
            ],
            'required' => ['title', 'content'],
        ];
    }

    public function execute(array $input, RecipeRunContext $ctx) {
        $title = trim((string)($input['title'] ?? ''));
        $content = (string)($input['content'] ?? '');
        $tags = $input['tags'] ?? null;

        if ($title === '') {
            return ['content' => 'save_note error: title is required.', 'is_error' => true];
        }
        if (mb_strlen($title) > self::MAX_TITLE_LEN) {
            return ['content' => 'save_note error: title exceeds ' . self::MAX_TITLE_LEN . ' chars.', 'is_error' => true];
        }
        if (mb_strlen($content) > self::MAX_CONTENT_CHARS) {
            return ['content' => 'save_note error: content exceeds ' . self::MAX_CONTENT_CHARS . ' chars.', 'is_error' => true];
        }
        if ($tags !== null && !is_array($tags)) {
            return ['content' => 'save_note error: tags must be an array of strings.', 'is_error' => true];
        }

        $note = RecipeNote::FindOrNewByTitle($ctx->owner_user_id, $title);
        $is_update = (bool)$note->key;

        $note->set('rcn_content', $content);
        if ($tags !== null) {
            $note->set('rcn_tags', array_values(array_map('strval', $tags)));
        }
        $note->set('rcn_update_time', gmdate('Y-m-d H:i:s'));
        $note->prepare();
        $note->save();

        return ($is_update ? 'Updated' : 'Created') . " note: '$title' (id "
            . (int)$note->key . ", " . mb_strlen($content) . " chars).";
    }

}
