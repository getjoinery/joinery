<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRender.php'));

/**
 * Structured-JSON view of a chat conversation and its turns for the /api/v1
 * chat actions (the native app surface). The parallel to ChatRender, which
 * emits admin-theme HTML for the web page: same source rows and same
 * model/cost helpers, but a machine-readable shape the client renders itself.
 * Times are handed back as raw UTC ('Y-m-d H:i:s'); the client localizes.
 */
class ChatSerializer {

    /** List-row summary: identity + sort/label state, no messages. */
    public static function conversationSummary(AiConversation $c): array {
        $title = trim((string)$c->get('aic_title'));
        return [
            'id'     => (int)$c->key,
            'title'  => $title !== '' ? $title : 'Untitled',
            'pinned' => (bool)$c->get('aic_pinned'),
        ];
    }

    /** Full conversation header for a thread load: the summary plus the
     *  effective model and the running usage label the composer bar shows. */
    public static function conversationDetail(AiConversation $c): array {
        return self::conversationSummary($c) + [
            'model'       => ChatRender::conversationModel($c),
            'usage_label' => self::usageLabel($c),
        ];
    }

    /** The cumulative "N tokens used · ~$cost" label for the conversation. */
    public static function usageLabel(AiConversation $c): string {
        $payload = ChatRender::conversationUsagePayload($c);
        return (string)($payload['label'] ?? '');
    }

    /**
     * One turn as structured data. `content` is raw markdown (assistant) or the
     * user's text — the client renders it. A non-null `pending_action` carries
     * the confirm-card description; `tool_calls` is the compact per-turn trace;
     * `usage` is this turn's token/cost line.
     */
    public static function message(AiConversationMessage $msg, string $model = ''): array {
        $in  = (int)$msg->get('aim_input_tokens');
        $out = (int)$msg->get('aim_output_tokens');

        $pending = $msg->get('aim_pending_action');
        if (is_string($pending)) $pending = json_decode($pending, true);
        $pending_out = null;
        if (is_array($pending) && !empty($pending)) {
            $pending_out = ['description' => (string)($pending['description'] ?? 'Run this action?')];
        }

        return [
            'id'             => (int)$msg->key,
            'role'           => (string)$msg->get('aim_role'),
            'content'        => (string)$msg->get('aim_content'),
            'status'         => (string)$msg->get('aim_status'),
            'error'          => (string)$msg->get('aim_error'),
            'created_time'   => (string)$msg->get('aim_create_time'),
            'pending_action' => $pending_out,
            'tool_calls'     => self::toolCalls($msg->get('aim_tool_calls')),
            'usage'          => [
                'input_tokens'  => $in,
                'output_tokens' => $out,
                'cost_label'    => ($in || $out)
                    ? ChatRender::formatCost(ChatRender::estimateCost($model, $in, $out)) : '',
            ],
        ];
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
