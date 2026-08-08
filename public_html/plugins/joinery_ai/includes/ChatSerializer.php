<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_message_attachments_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRender.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));

/**
 * Structured-JSON view of a chat conversation and its turns for the /api/v1
 * chat actions (the native app surface). The parallel to ChatRender, which
 * emits admin-theme HTML for the web page: same source rows and same
 * model/cost helpers, but a machine-readable shape the client renders itself.
 * Times are handed back as raw UTC ('Y-m-d H:i:s'); the client localizes.
 */
class ChatSerializer {

    /** List-row summary: identity + sort/label state, no messages. On a locked
     *  protected conversation the title (content) is withheld — a placeholder and
     *  a `locked` flag stand in, so the list still renders/sorts (times, pinned,
     *  level are cleartext) and the client prompts unlock to read it. */
    public static function conversationSummary(AiConversation $c): array {
        $level  = (string)$c->get('aic_security_level');
        $locked = ChatSeal::isLocked($c);
        if ($locked) {
            $title = ChatSeal::LOCKED_TITLE;
        } else {
            $title = trim((string)$c->get('aic_title'));
            if ($title === '') $title = 'Untitled';
        }
        $out = [
            'id'             => (int)$c->key,
            'title'          => $title,
            'pinned'         => (bool)$c->get('aic_pinned'),
            'security_level' => $level ?: AiConversation::LEVEL_STANDARD,
            'protected'      => ChatSeal::isProtectedLevel($level),
        ];
        if ($locked) $out['locked'] = true;
        return $out;
    }

    /** Full conversation header for a thread load: the summary plus the
     *  effective model, the running usage label the composer bar shows, and the
     *  per-chat control values (blank/null numerics = inherit the default). */
    public static function conversationDetail(AiConversation $c): array {
        return self::conversationSummary($c) + [
            'model'       => ChatRender::conversationModel($c),
            'usage_label' => self::usageLabel($c),
            'controls'    => self::controls($c),
        ];
    }

    /** The stored per-chat controls. Numerics are null when unset (the chat
     *  inherits the plugin-setting default at run time). */
    public static function controls(AiConversation $c): array {
        $numOrNull = function ($v) {
            return ($v === null || $v === '') ? null : (float)$v;
        };
        $maxTokens = $c->get('aic_max_tokens');
        return [
            'model'          => (string)$c->get('aic_model'),
            'data_access'    => (bool)$c->get('aic_data_access'),
            'web_search'     => (bool)$c->get('aic_web_search'),
            'history_access' => (bool)$c->get('aic_history_access'),
            'thinking_level' => (string)$c->get('aic_thinking_level'),
            'temperature'    => $numOrNull($c->get('aic_temperature')),
            'top_p'          => $numOrNull($c->get('aic_top_p')),
            'max_tokens'     => ($maxTokens === null || $maxTokens === '') ? null : (int)$maxTokens,
            'instructions'   => (string)$c->get('aic_instructions'),
        ];
    }

    /** The cumulative "N tokens used · ~$cost" label for the conversation. */
    public static function usageLabel(AiConversation $c): string {
        $payload = ChatRender::conversationUsagePayload($c);
        return (string)($payload['label'] ?? '');
    }

    /**
     * The live-status extras for a RUNNING assistant row: the runner's current
     * stage label and the server-computed elapsed seconds, so every client
     * shows the same truthful "what's happening" line without clock math
     * against DB timestamp strings (specs/ai_chat_turn_activity.md). Empty
     * array for any non-running row — callers can merge unconditionally.
     */
    public static function runningExtras(AiConversationMessage $msg): array {
        if ((string)$msg->get('aim_status') !== AiConversationMessage::STATUS_RUNNING) {
            return [];
        }
        $out = [];
        $activity = (string)$msg->get('aim_activity');
        if ($activity !== '') $out['activity'] = $activity;
        $started = strtotime((string)$msg->get('aim_create_time') . ' UTC');
        if ($started !== false) {
            $out['running_seconds'] = max(0, time() - $started);
        }
        return $out;
    }

    /**
     * One turn as structured data. `content` is raw markdown (assistant), the
     * user's text, or an event row's resolution note — the client renders it.
     * `tool_calls` is the compact per-turn trace; `usage` is this turn's
     * token/cost line. A running row additionally carries the live-status
     * extras (see runningExtras()).
     */
    public static function message(AiConversationMessage $msg, string $model = ''): array {
        $in  = (int)$msg->get('aim_input_tokens');
        $out = (int)$msg->get('aim_output_tokens');
        // Window usage is the thread's resting size as of this turn, not the billed
        // input total (which re-counts system + history per tool-loop step). Legacy
        // rows predate the column and fall back to the billed total.
        $used_raw = $msg->get('aim_context_used');
        $used = ($used_raw === null || $used_raw === '' || (int)$used_raw <= 0) ? $in : (int)$used_raw;
        $win_raw = $msg->get('aim_context_window');
        $window  = ($win_raw === null || $win_raw === '') ? null : (int)$win_raw;

        return [
            'id'             => (int)$msg->key,
            'role'           => (string)$msg->get('aim_role'),
            'content'        => (string)$msg->get('aim_content'),
            'status'         => (string)$msg->get('aim_status'),
            'error'          => (string)$msg->get('aim_error'),
            'created_time'   => (string)$msg->get('aim_create_time'),
            'attachments'    => AiMessageAttachment::displayListForMessage((int)$msg->key),
            'tool_calls'     => self::toolCalls($msg->get('aim_tool_calls')),
            'usage'          => [
                'input_tokens'   => $in,
                'output_tokens'  => $out,
                'context_used'   => $used,
                'context_window' => $window,
                'context_band'   => ChatRender::contextBand($used, $window),
                'cost_label'     => ($in || $out)
                    ? ChatRender::formatCost(ChatRender::estimateCost($model, $in, $out)) : '',
            ],
        ] + self::runningExtras($msg);
    }

    /** Compact per-turn tool trace: name, error flag, duration. */
    private static function toolCalls($tool_calls): array {
        if (is_string($tool_calls)) $tool_calls = json_decode($tool_calls, true);
        if (!is_array($tool_calls)) return [];
        $out = [];
        foreach ($tool_calls as $tc) {
            if (!is_array($tc)) continue;
            $out[] = [
                'name'        => (string)($tc['name'] ?? '?'),
                'is_error'    => !empty($tc['is_error']),
                'duration_ms' => isset($tc['duration_ms']) && $tc['duration_ms'] !== null
                    ? (int)$tc['duration_ms'] : null,
            ];
        }
        return $out;
    }
}
