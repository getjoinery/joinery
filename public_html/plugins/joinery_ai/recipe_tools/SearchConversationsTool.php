<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatTurnContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRunner.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatLevel.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));

/**
 * Search the acting user's OWN past chat conversations by keyword — so the
 * assistant can pull in something decided in an earlier thread.
 * (specs/chat_search_past_conversations_tool.md.)
 *
 * Two rules shape it, both about privacy:
 *   - It only ever searches the calling user's own conversations (owner-scoped by
 *     $ctx->actingUserId()).
 *   - It respects the encryption boundary. A protected (Private/Fortress) chat's
 *     decrypted content is surfaced ONLY when the current turn runs on a local
 *     model with the owner's vault open; on a remote model (or a locked vault) the
 *     response carries a fixed, query-independent, count-free note that protected
 *     history was skipped and how to include it — never the content, and never a
 *     per-query count that would turn the tool into a keyword oracle over sealed
 *     data (§3).
 *
 * Chat-only: it reaches the turn's model through the concrete ChatTurnContext
 * (like ViewAttachmentTool reads conversationId()), which a RecipeRunContext has
 * no equivalent of — and it's never offered under a recipe (its own gate lives on
 * the conversation, §5). All ciphertext / vault / snippet handling stays behind
 * MultiAiConversation::searchForTool(); this class only computes the surface gate
 * and formats the result.
 */
class SearchConversationsTool implements RecipeToolInterface {

    const DEFAULT_LIMIT = 10;
    const MAX_LIMIT = 25;

    public static function name(): string {
        return 'search_conversations';
    }

    public static function description(): string {
        return 'Search the user\'s own past chat conversations by keyword. Returns '
             . 'matching conversations with a title, date, id, and a short snippet. '
             . 'Use when the user refers to something discussed in an earlier chat '
             . '("what did we decide about…", "the thread where we talked about…"). '
             . 'Only the user\'s own conversations are searched. The user\'s '
             . 'protected (private) conversations may be withheld — the result says '
             . 'so when they are.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'required' => ['query'],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Keywords to search for in past conversation titles and messages.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many conversations to return (1-25, default 10).',
                    'minimum' => 1,
                    'maximum' => 25,
                ],
            ],
        ];
    }

    public function execute(array $input, ToolContext $ctx) {
        if (!($ctx instanceof ChatTurnContext)) {
            return ['content' => 'search_conversations is only available in chat.', 'is_error' => true];
        }

        $query = trim((string)($input['query'] ?? ''));
        if ($query === '') {
            return ['content' => 'Provide a search query (keywords to look for).', 'is_error' => true];
        }
        $limit = (int)($input['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit < 1) $limit = 1;
        if ($limit > self::MAX_LIMIT) $limit = self::MAX_LIMIT;

        $uid = $ctx->actingUserId();

        // The surface gate: protected content is surfaced only when this turn's
        // model is local AND the owner's vault window is open. The turn runs on the
        // conversation's model, falling back to the resolved default exactly as
        // ChatRunner does (a protected chat is already pinned to a local model).
        $model = $ctx->conversationModel();
        if ($model === '') $model = ChatRunner::defaultModel();
        $surface_protected = ChatLevel::isLocalModel($model) && ChatSeal::windowOpenFor($uid);

        // Exclude the active conversation so the model never "finds" the thread it
        // is already in (§7).
        $res = MultiAiConversation::searchForTool($uid, $query, $limit, $surface_protected, $ctx->conversationId());
        return self::format($res, $ctx->ownerTimezone());
    }

    /** Render the seam result as the tool's text result. The withheld/locked/capped
     *  notes are FIXED strings — never varying with the query or a match count — so
     *  a remote turn can't be used to probe sealed content (§3, acceptance #8). */
    private static function format(array $res, string $tz): string {
        $matches = $res['matches'];
        $lines = [];

        if (empty($matches)) {
            $lines[] = 'No matching conversations found.';
        } else {
            $lines[] = count($matches) === 1 ? '1 matching conversation:' : count($matches) . ' matching conversations:';
            $lines[] = '';
            foreach ($matches as $m) {
                // Collapse whitespace so a title with an embedded newline can't smear
                // the Markdown heading across lines.
                $title = trim((string)preg_replace('/\s+/', ' ', (string)$m['title']));
                $lines[] = '## ' . ($title !== '' ? $title : '(untitled)');
                $meta = 'id ' . (int)$m['id'];
                if (!empty($m['date'])) {
                    $when = LibraryFunctions::convert_time($m['date'], 'UTC', $tz, 'M j, Y');
                    if ($when) $meta .= ' · ' . $when;
                }
                $lines[] = '_' . $meta . '_';
                $snippet = trim((string)$m['snippet']);
                if ($snippet !== '') {
                    $lines[] = '';
                    $lines[] = $snippet;
                }
                $lines[] = '';
            }
        }

        // Protected-history acknowledgment (fixed, query-independent, count-free).
        if (!empty($res['locked'])) {
            $lines[] = 'Some of your protected conversations weren\'t searched because your vault is locked. '
                     . 'Unlock it to include them.';
        } elseif (!empty($res['protected_withheld'])) {
            $lines[] = 'Your protected conversations aren\'t searched while this chat is on a remote model. '
                     . 'Switch to a local model to include them.';
        }
        if (!empty($res['protected_capped'])) {
            $lines[] = 'Some older protected conversations weren\'t searched.';
        }

        return trim(implode("\n", $lines));
    }
}
