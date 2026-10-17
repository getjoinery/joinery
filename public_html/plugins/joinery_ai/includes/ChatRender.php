<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/message_attachments_class.php'));

/**
 * Renders chat transcript markup. The page view (initial load) and the
 * send/confirm AJAX endpoints both go through here so a freshly-returned turn
 * looks identical to one rendered on reload — no drift between the two paths.
 *
 * A turn is drawn by the core ChatTranscript (one renderer for every
 * conversation with a model, styled by assets/css/chat_transcript.css); this
 * class adds what is Joinery AI's own: attachments, usage and cost, the
 * Copy/Delete actions, and event chips.
 */
class ChatRender {

    /** A user-authored bubble. A message id enables the per-turn actions
     *  (copy / delete); the optimistic bubble built client-side on send omits it
     *  until the page reloads with the persisted row. */
    public static function userBubble(string $text, string $time, int $message_id = 0): string {
        return ChatTranscript::userBubble($text, $time, [
            'message_id'       => $message_id,
            'attachments_html' => $message_id
                ? self::attachmentsHtml(AiMessageAttachment::displayListForMessage($message_id)) : '',
            'actions_html'     => $message_id ? self::actionsHtml() : '',
        ]);
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

    /** Estimated USD for an input/output token pair, at the catalog's rates for
     *  that model. A model with no declared cost — every local one — prices at
     *  0. Best-effort: never throws. */
    public static function estimateCost(string $model, int $in, int $out): float {
        if ($model === '' || ($in === 0 && $out === 0)) return 0.0;
        try {
            return AiModelResolution::costFor($model, ['input_tokens' => $in, 'output_tokens' => $out]);
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    /** A reply's cost: what the turn recorded, or an estimate from its tokens
     *  for a row written before the cost was recorded. */
    public static function rowCost(AiConversationMessage $msg, string $model): float {
        $recorded = $msg->get('aim_cost_estimate');
        if ($recorded !== null && $recorded !== '') {
            return (float)$recorded;
        }
        return self::estimateCost($model, (int)$msg->get('aim_input_tokens'), (int)$msg->get('aim_output_tokens'));
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

    /** Token count in ≈2 significant figures: 780, 3.6k, 78k, 120k, 1.2M. Keeps
     *  the footer legible — the exact count is never the point, the magnitude is. */
    public static function compactTokens(int $n): string {
        if ($n < 1000) return (string)$n;
        // One decimal only below 10 of a unit (3.6k), whole numbers above (78k,
        // 120k) — and a lone decimal digit is trimmed when it's a zero (3.0k → 3k).
        if ($n < 1000000) {
            $k = $n / 1000;
            $s = $k < 10 ? rtrim(rtrim(number_format($k, 1), '0'), '.') : number_format($k, 0);
            return $s . 'k';
        }
        $m = $n / 1000000;
        return rtrim(rtrim(number_format($m, 1), '0'), '.') . 'M';
    }

    /** Color band for the context number by how close it is to the model's real
     *  context window (captured per turn from the host): amber "approaching the
     *  limit" at 70%, red "extremely close" at 90% — past the window a local model
     *  starts dropping the oldest turns. Gray when the window is unknown (remote
     *  models, or the host didn't report one) — a plain count, nothing to grade. */
    public static function contextBand(int $in, ?int $window): string {
        if ($window === null || $window <= 0 || $in <= 0) return 'joai-ctx-ok';
        $frac = $in / $window;
        if ($frac >= 0.90) return 'joai-ctx-high';
        if ($frac >= 0.70) return 'joai-ctx-warn';
        return 'joai-ctx-ok';
    }

    /** The metadata pieces for a turn's footer line: how full the context window is
     *  (color-flagged) and the estimated cost, each dropped when it would read as
     *  zero. $used is the conversation's size as of this turn — system prompt plus
     *  every prior exchange plus this one. Because a conversation only grows, the
     *  colour derived from it never travels backwards: amber stays amber and red
     *  stays red, which is the intent. Red means the oldest exchanges are being
     *  dropped on every turn from here on, and only a new conversation resets it.
     *  Deliberately NOT the turn's billed input total, which re-counts the system
     *  prompt and history once per tool-loop step and so jumps around with tool use. */
    public static function turnUsageParts(int $used, float $usd, ?int $window = null,
            int $input_total = 0, int $cache_read = 0): array {
        $parts = [];
        if ($used > 0) {
            $title = ($window !== null && $window > 0)
                ? 'This conversation is using ' . self::compactTokens($used) . ' of the model\'s '
                    . self::compactTokens($window) . ' context window ('
                    . (int)round($used / $window * 100) . '%). It only grows, and once it fills, the'
                    . ' model drops the oldest exchanges on every turn from then on — start a new'
                    . ' conversation before that.'
                : 'How much the conversation is carrying as of this turn.';
            $parts[] = '<span class="joai-ctx ' . self::contextBand($used, $window) . '" title="'
                . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">'
                . self::compactTokens($used) . ' ctx</span>';
        }
        // How much of the input the provider served from its prompt cache: the
        // fixed opening of every turn should come back from it at a fraction of
        // the input price.
        if ($cache_read > 0 && $input_total > 0) {
            $title = number_format($cache_read) . ' of the ' . number_format($input_total)
                . ' input tokens came from the provider\'s cache.';
            $parts[] = '<span class="joai-chat-usage-cache" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">'
                . (int)round($cache_read / $input_total * 100) . '% cached</span>';
        }
        $cost = self::formatCost($usd);
        if ($cost !== '') {
            $parts[] = '<span class="joai-chat-usage-cost">'
                . htmlspecialchars($cost, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        return $parts;
    }

    /** The single footer row under a bubble: an optional tool trace on the left,
     *  then time · context · cost, with the hover-only Copy/Delete actions pushed
     *  right. `$meta_extra` are the turn-usage parts (empty for user bubbles). */
    public static function footHtml(string $trace, string $time, array $meta_extra, bool $with_actions): string {
        return ChatTranscript::footHtml($trace, $time, $meta_extra, $with_actions ? self::actionsHtml() : '');
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
     * An assistant bubble for a stored message row: markdown body and an
     * optional collapsible tool trace. (Proposed writes live on the approval
     * queue, rendered by the queue-card JS, not inside the bubble.)
     */
    public static function assistantBubble(AiConversationMessage $msg, string $tz, string $model = ''): string {
        $time = LibraryFunctions::convert_time(
            $msg->get('aim_create_time'), 'UTC', $tz, 'g:i A'
        );

        $body_md = (string)$msg->get('aim_content');

        $trace = self::traceHtml($msg->get('aim_tool_calls'));

        $in  = (int)$msg->get('aim_input_tokens');
        $out = (int)$msg->get('aim_output_tokens');
        $win_raw = $msg->get('aim_context_window');
        $window = ($win_raw === null || $win_raw === '') ? null : (int)$win_raw;
        // Cost is billed on every call in the loop ($in); the badge tracks the
        // thread's resting size. Rows written before that was recorded have no
        // value — fall back to $in so they still show something, knowing that on a
        // tool-heavy turn it reads high.
        $used_raw = $msg->get('aim_context_used');
        $used = ($used_raw === null || $used_raw === '' || (int)$used_raw <= 0)
            ? $in + (int)$msg->get('aim_cache_read_tokens') + (int)$msg->get('aim_cache_write_tokens') : (int)$used_raw;
        $meta_extra = self::turnUsageParts($used, self::rowCost($msg, $model), $window,
            $in + (int)$msg->get('aim_cache_read_tokens') + (int)$msg->get('aim_cache_write_tokens'),
            (int)$msg->get('aim_cache_read_tokens'));

        // A turn the user stopped mid-flight keeps its partial answer and carries
        // a small "Cancelled" marker — persistent across reloads (keyed on the
        // stored status), not just in the live poll.
        return ChatTranscript::assistantBubble($body_md, $time, [
            'message_id'   => (int)$msg->key,
            'trace_html'   => $trace,
            'meta_parts'   => $meta_extra,
            'cancelled'    => (string)$msg->get('aim_status') === AiConversationMessage::STATUS_CANCELLED,
            'actions_html' => self::actionsHtml(),
        ]);
    }

    /** Above this, an event chip collapses its tail behind a details toggle —
     *  an approved fetch carries its whole result in the event row. */
    const EVENT_CHIP_INLINE_MAX = 400;

    /** A neutral transcript chip for an EVENT row — a queued action's
     *  resolution. Platform-written fact, styled apart from both voices.
     *  Long content (an approved egress action's carried result) shows its
     *  head inline and the rest behind a toggle. */
    public static function eventChip(string $content, string $time): string {
        $time_html = '<span class="joai-chat-event-time">'
            . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '</span>';

        if (mb_strlen($content) <= self::EVENT_CHIP_INLINE_MAX) {
            return '<div class="joai-chat-event">'
                 . '<span class="joai-chat-event-text">' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</span>'
                 . $time_html
                 . '</div>';
        }

        $head = mb_substr($content, 0, self::EVENT_CHIP_INLINE_MAX);
        return '<div class="joai-chat-event">'
             . '<span class="joai-chat-event-text">' . htmlspecialchars($head, ENT_QUOTES, 'UTF-8') . '…</span>'
             . $time_html
             . '<details class="joai-chat-event-more"><summary>Show all '
             . number_format(mb_strlen($content)) . ' characters</summary>'
             . '<pre>' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</pre>'
             . '</details>'
             . '</div>';
    }

    /** Collapsible per-turn tool trace. Empty string when there were none. */
    public static function traceHtml($tool_calls): string {
        return ChatTranscript::traceHtml($tool_calls);
    }

}
