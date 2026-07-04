<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_message_attachments_class.php'));

/**
 * Renders chat transcript markup. The page view (initial load) and the
 * send/confirm AJAX endpoints both go through here so a freshly-returned turn
 * looks identical to one rendered on reload — no drift between the two paths.
 *
 * Admin theme is not the .jy-ui kit, so the markup uses plain joai-chat-*
 * classes styled in joinery_ai.css.
 */
class ChatRender {

    /** A user-authored bubble. A message id enables the per-turn actions
     *  (copy / delete); the optimistic bubble built client-side on send omits it
     *  until the page reloads with the persisted row. */
    public static function userBubble(string $text, string $time, int $message_id = 0): string {
        $raw = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $id_attr = $message_id ? ' data-message-id="' . $message_id . '"' : '';
        $attachments = $message_id
            ? self::attachmentsHtml(AiMessageAttachment::displayListForMessage($message_id)) : '';
        $body = $raw !== '' ? '<div class="joai-chat-body">' . nl2br($raw) . '</div>' : '';
        return '<div class="joai-chat-msg joai-chat-mine"' . $id_attr . ' data-raw="' . $raw . '">'
             . $body
             . $attachments
             . ($message_id ? self::actionsHtml() : '')
             . '<div class="joai-chat-time">' . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '</div>'
             . '</div>';
    }

    /**
     * Attachment chips/thumbnails for a user bubble, from the display list
     * (AiMessageAttachment::displayListForMessage). Images render as a small
     * thumbnail linking to the gated original; other types render as a labeled
     * file chip. Empty string when there are no attachments.
     */
    public static function attachmentsHtml(array $list): string {
        if (empty($list)) return '';
        $chips = '';
        foreach ($list as $a) {
            $name = htmlspecialchars((string)($a['name'] ?? 'file'), ENT_QUOTES, 'UTF-8');
            $category = (string)($a['category'] ?? 'file');
            if ($category === 'image' && !empty($a['image_url'])) {
                $url = htmlspecialchars((string)$a['image_url'], ENT_QUOTES, 'UTF-8');
                $chips .= '<a class="joai-chat-attach joai-chat-attach-image" href="' . $url . '" '
                    . 'target="_blank" rel="noopener" title="' . $name . '">'
                    . '<img src="' . $url . '" alt="' . $name . '"></a>';
            } else {
                $icon = self::attachmentIcon($category);
                $chips .= '<span class="joai-chat-attach joai-chat-attach-file" title="' . $name . '">'
                    . '<span class="joai-chat-attach-icon" aria-hidden="true">' . $icon . '</span>'
                    . '<span class="joai-chat-attach-name">' . $name . '</span></span>';
            }
        }
        return '<div class="joai-chat-attachments">' . $chips . '</div>';
    }

    /** A short glyph for a non-image attachment category. */
    private static function attachmentIcon(string $category): string {
        switch ($category) {
            case 'pdf':  return '📄';
            case 'html': return '🌐';
            case 'text': return '📝';
            default:     return '📎';
        }
    }

    /** Effective model for a conversation: its pinned model, else the plugin
     *  default. Used to price token usage (a thread has one model). */
    public static function conversationModel(AiConversation $conversation): string {
        $model = trim((string)$conversation->get('aic_model'));
        if ($model !== '') return $model;
        return (string)Globalvars::get_instance()->get_setting('joinery_ai_default_model');
    }

    /** Estimated USD for an input/output token pair under a model's provider
     *  pricing. Local/unknown models price at 0. Best-effort: never throws. */
    public static function estimateCost(string $model, int $in, int $out): float {
        if ($model === '' || ($in === 0 && $out === 0)) return 0.0;
        try {
            require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));
            $provider = LlmProviderFactory::forModel($model);
            return (float)$provider->estimateCost($model, ['input_tokens' => $in, 'output_tokens' => $out]);
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    /** Compact dollar label: blank for $0 (free/local), else ~$0.0123 — the
     *  leading ~ signals it is an estimate (cache tiers aren't broken out). */
    public static function formatCost(float $usd): string {
        if ($usd <= 0) return '';
        return $usd < 0.01 ? '~$' . number_format($usd, 4) : '~$' . number_format($usd, 2);
    }

    /** The conversation-total label for the bar under the composer. Reads as
     *  cumulative consumption ("used") so it's never mistaken for a limit. */
    public static function conversationUsageLabel(int $in, int $out, float $usd): string {
        $label = number_format($in + $out) . ' tokens used';
        $cost = self::formatCost($usd);
        if ($cost !== '') $label .= ' · ' . $cost;
        return $label;
    }

    /** Fresh conversation-total payload (one preformatted label) for completion
     *  responses, so the page can update the bar without a reload. */
    public static function conversationUsagePayload(AiConversation $conversation): array {
        $in  = (int)$conversation->get('aic_total_input_tokens');
        $out = (int)$conversation->get('aic_total_output_tokens');
        $usd = self::estimateCost(self::conversationModel($conversation), $in, $out);
        return ['label' => self::conversationUsageLabel($in, $out, $usd)];
    }

    /** Per-turn token + cost line shown at the bottom of an assistant reply.
     *  "context" is everything fed to the model this turn (system prompt, history,
     *  and any tool/web results across the tool loop — often far larger than the
     *  reply); "reply" is the generated output the Max-reply-length cap governs. */
    public static function turnUsageHtml(int $in, int $out, float $usd): string {
        if ($in === 0 && $out === 0) return '';
        $cost = self::formatCost($usd);
        $cost_html = $cost !== ''
            ? ' · <span class="joai-chat-usage-cost">' . htmlspecialchars($cost, ENT_QUOTES, 'UTF-8') . '</span>'
            : '';
        return '<div class="joai-chat-usage" title="Context fed to the model this turn, then the reply it generated, with estimated cost">'
             . number_format($in) . ' context · ' . number_format($out) . ' reply'
             . $cost_html . '</div>';
    }

    /** Per-turn action toolbar (copy / delete). Shared by both bubble kinds; the
     *  raw text to copy rides on the bubble's data-raw attribute. */
    public static function actionsHtml(): string {
        return '<div class="joai-chat-actions">'
             . '<button type="button" class="joai-chat-action" data-action="copy" aria-label="Copy message">Copy</button>'
             . '<button type="button" class="joai-chat-action joai-chat-action-danger" data-action="delete" aria-label="Delete message">Delete</button>'
             . '</div>';
    }

    /**
     * An assistant bubble for a stored message row: markdown body, optional
     * collapsible tool trace, and — when the row still carries a pending
     * action — the confirmation card.
     */
    public static function assistantBubble(AiConversationMessage $msg, string $tz, string $model = ''): string {
        $time = LibraryFunctions::convert_time(
            $msg->get('aim_create_time'), 'UTC', $tz, 'g:i A'
        );

        $body_md = (string)$msg->get('aim_content');
        $body_html = self::renderMarkdown($body_md);

        $trace = self::traceHtml($msg->get('aim_tool_calls'));

        $in  = (int)$msg->get('aim_input_tokens');
        $out = (int)$msg->get('aim_output_tokens');
        $usage = self::turnUsageHtml($in, $out, self::estimateCost($model, $in, $out));

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

        return '<div class="joai-chat-msg joai-chat-assistant" data-message-id="' . (int)$msg->key . '"'
             . ' data-raw="' . htmlspecialchars($body_md, ENT_QUOTES, 'UTF-8') . '">'
             . '<div class="joai-chat-body">' . $body_html . '</div>'
             . $trace
             . $card
             . self::actionsHtml()
             . '<div class="joai-chat-msg-meta">'
             . $usage
             . '<div class="joai-chat-time">' . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '</div>'
             . '</div>'
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
