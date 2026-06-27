<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));

/**
 * Renders chat transcript markup. The page view (initial load) and the
 * send/confirm AJAX endpoints both go through here so a freshly-returned turn
 * looks identical to one rendered on reload — no drift between the two paths.
 *
 * Admin theme is not the .jy-ui kit, so the markup uses plain joai-chat-*
 * classes styled in joinery_ai.css.
 */
class ChatRender {

    /** A user-authored bubble. */
    public static function userBubble(string $text, string $time): string {
        return '<div class="joai-chat-msg joai-chat-mine">'
             . '<div class="joai-chat-body">' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</div>'
             . '<div class="joai-chat-time">' . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '</div>'
             . '</div>';
    }

    /**
     * An assistant bubble for a stored message row: markdown body, optional
     * collapsible tool trace, and — when the row still carries a pending
     * action — the confirmation card.
     */
    public static function assistantBubble(AiConversationMessage $msg, string $tz): string {
        $time = LibraryFunctions::convert_time(
            $msg->get('aim_create_time'), 'UTC', $tz, 'g:i A'
        );

        $body_md = (string)$msg->get('aim_content');
        $body_html = self::renderMarkdown($body_md);

        $trace = self::traceHtml($msg->get('aim_tool_calls'));

        $pending = $msg->get('aim_pending_action');
        if (is_string($pending)) $pending = json_decode($pending, true);
        $card = '';
        if (is_array($pending) && !empty($pending)) {
            $card = self::pendingCardHtml(
                $pending,
                (int)$msg->get('aim_aic_conversation_id'),
                (int)$msg->key
            );
        }

        return '<div class="joai-chat-msg joai-chat-assistant" data-message-id="' . (int)$msg->key . '">'
             . '<div class="joai-chat-body">' . $body_html . '</div>'
             . $trace
             . $card
             . '<div class="joai-chat-time">' . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '</div>'
             . '</div>';
    }

    /** The Confirm/Cancel card for a held mutating call. */
    public static function pendingCardHtml(array $pending, int $conversation_id, int $message_id): string {
        $desc = (string)($pending['description'] ?? 'Run this action?');
        return '<div class="joai-chat-confirm" data-conversation-id="' . $conversation_id
             . '" data-message-id="' . $message_id . '">'
             . '<div class="joai-chat-confirm-desc">'
             . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '</div>'
             . '<div class="joai-chat-confirm-actions">'
             . '<button type="button" class="joai-btn joai-btn-primary joai-chat-confirm-yes">Confirm</button> '
             . '<button type="button" class="joai-btn joai-chat-confirm-no">Cancel</button>'
             . '</div></div>';
    }

    /** Collapsible per-turn tool trace. Empty string when there were none. */
    public static function traceHtml($tool_calls): string {
        if (is_string($tool_calls)) $tool_calls = json_decode($tool_calls, true);
        if (!is_array($tool_calls) || empty($tool_calls)) return '';

        $items = '';
        foreach ($tool_calls as $tc) {
            $name = htmlspecialchars((string)($tc['name'] ?? '?'), ENT_QUOTES, 'UTF-8');
            $is_error = !empty($tc['is_error']);
            $dur = isset($tc['duration_ms']) && $tc['duration_ms'] !== null
                ? ' · ' . (int)$tc['duration_ms'] . 'ms' : '';
            $cls = $is_error ? ' joai-chat-tool-error' : '';
            $items .= '<li class="joai-chat-tool' . $cls . '">' . $name . $dur . '</li>';
        }
        $count = count($tool_calls);
        return '<details class="joai-chat-trace"><summary>' . $count . ' tool call'
             . ($count === 1 ? '' : 's') . '</summary><ul>' . $items . '</ul></details>';
    }

    /** Markdown → HTML for assistant text. Falls back to escaped text if the
     *  renderer isn't available. */
    private static function renderMarkdown(string $md): string {
        if ($md === '') return '';
        try {
            require_once(PathHelper::getIncludePath('includes/MarkdownRenderer.php'));
            return MarkdownRenderer::render($md);
        } catch (Throwable $e) {
            return nl2br(htmlspecialchars($md, ENT_QUOTES, 'UTF-8'));
        }
    }

}
