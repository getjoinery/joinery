<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRunner.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatAsync.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderException.php'));

/**
 * The turn-execution half of chat: run a fresh turn (or resume a confirmed one)
 * onto the assistant placeholder row, roll up token totals, and mark
 * failure/title. Shared by the web AJAX endpoints (views/admin/chat_send.php,
 * chat_confirm.php) and the /api/v1 chat actions so both surfaces run a turn
 * identically — one place owns the finalize sequence, no drift between them.
 */
class ChatTurn {

    /**
     * Run one turn and write the result onto the pre-created placeholder row,
     * then roll up the conversation token totals. Any failure marks the row
     * FAILED with an error the poller surfaces. Never echoes.
     */
    public static function runAndFinalize(AiConversation $conversation, int $uid,
            AiConversationMessage $assistant_msg): void {
        try {
            $turn = ChatRunner::runTurn($conversation, $uid, ChatAsync::streamSink($assistant_msg));
        } catch (LlmProviderException $e) {
            error_log('[joinery_ai chat] provider error: ' . $e->getMessage());
            self::markFailed($assistant_msg,
                LlmProviderException::friendlyMessage(LlmProviderException::classify($e)));
            return;
        } catch (Throwable $e) {
            error_log('[joinery_ai chat] turn failed: ' . $e->getMessage());
            self::markFailed($assistant_msg, 'The assistant could not complete this turn.');
            return;
        }

        $result = $turn['result'];
        $ctx    = $turn['context'];

        $assistant_msg->set('aim_content', ChatRunner::resolveAssistantText($result));
        $assistant_msg->set('aim_tool_calls', $ctx->toolCalls());
        if (!empty($result['pending_action'])) {
            $assistant_msg->set('aim_pending_action', $result['pending_action']);
        }
        $assistant_msg->set('aim_input_tokens', (int)$result['input_tokens']);
        $assistant_msg->set('aim_output_tokens', (int)$result['output_tokens']);
        $assistant_msg->set('aim_status', AiConversationMessage::STATUS_COMPLETE);
        $assistant_msg->save();

        self::rollupUsage($conversation, (int)$result['input_tokens'], (int)$result['output_tokens']);
    }

    /**
     * Continue the turn from a confirmation decision and fold the resumed reply
     * into the same assistant row (one assistant message per turn). Any failure
     * marks the row FAILED with an error the poller surfaces. Never echoes.
     */
    public static function resumeAndFinalize(AiConversation $conversation, int $uid,
            AiConversationMessage $msg, array $pending, string $lead_text, string $decision): void {
        try {
            $seed = $lead_text !== '' ? $lead_text . "\n\n" : '';
            $sink = ChatAsync::streamSink($msg, $seed);
            $turn = ChatRunner::resumeTurn($conversation, $uid, $pending, $lead_text, $decision, $sink);
        } catch (LlmProviderException $e) {
            error_log('[joinery_ai chat] resume provider error: ' . $e->getMessage());
            self::markFailed($msg,
                LlmProviderException::friendlyMessage(LlmProviderException::classify($e)));
            return;
        } catch (Throwable $e) {
            error_log('[joinery_ai chat] resume failed: ' . $e->getMessage());
            self::markFailed($msg, 'The action could not be completed.');
            return;
        }

        $result = $turn['result'];
        $ctx    = $turn['context'];

        $resumed_text = ChatRunner::resolveAssistantText($result);
        $combined = trim($lead_text
            . (($lead_text !== '' && $resumed_text !== '') ? "\n\n" : '') . $resumed_text);
        if ($combined === '') $combined = ($decision === 'confirm') ? 'Done.' : 'Okay, I won’t do that.';

        $merged_trace = array_merge(self::decodeTrace($msg->get('aim_tool_calls')), $ctx->toolCalls());

        $msg->set('aim_content', $combined);
        $msg->set('aim_tool_calls', $merged_trace);
        $msg->set('aim_pending_action', !empty($result['pending_action']) ? $result['pending_action'] : null);
        $msg->set('aim_input_tokens', (int)$msg->get('aim_input_tokens') + (int)$result['input_tokens']);
        $msg->set('aim_output_tokens', (int)$msg->get('aim_output_tokens') + (int)$result['output_tokens']);
        $msg->set('aim_status', AiConversationMessage::STATUS_COMPLETE);
        $msg->save();

        self::rollupUsage($conversation, (int)$result['input_tokens'], (int)$result['output_tokens']);
    }

    /** Add a turn's tokens to the conversation totals and bump its update time. */
    private static function rollupUsage(AiConversation $conversation, int $in, int $out): void {
        $conversation->set('aic_total_input_tokens',
            (int)$conversation->get('aic_total_input_tokens') + $in);
        $conversation->set('aic_total_output_tokens',
            (int)$conversation->get('aic_total_output_tokens') + $out);
        $conversation->set('aic_update_time', gmdate('Y-m-d H:i:s'));
        $conversation->save();
    }

    public static function markFailed(AiConversationMessage $msg, string $error): void {
        $msg->set('aim_status', AiConversationMessage::STATUS_FAILED);
        $msg->set('aim_error', $error);
        $msg->save();
    }

    /** A short thread title derived from the first user message. */
    public static function deriveTitle(string $message): string {
        $t = trim(preg_replace('/\s+/', ' ', $message));
        if (mb_strlen($t) > 60) $t = mb_substr($t, 0, 57) . '…';
        return $t !== '' ? $t : 'New chat';
    }

    /**
     * If a prior turn left an unconfirmed proposal, sending a new message
     * abandons it — record that in the transcript and clear the pending action
     * so the history stays a valid, alternating sequence.
     */
    public static function clearDanglingPending(AiConversation $conversation): void {
        $rows = new MultiAiConversationMessage(
            ['conversation_id' => (int)$conversation->key,
             'role' => AiConversationMessage::ROLE_ASSISTANT, 'deleted' => false],
            ['aim_message_id' => 'DESC'], 1, 0
        );
        $rows->load();
        if (!count($rows)) return;
        $last = $rows->get(0);
        $pending = $last->get('aim_pending_action');
        if (is_string($pending)) $pending = json_decode($pending, true);
        if (empty($pending)) return;

        $txt = (string)$last->get('aim_content');
        $note = '_(Proposed an action; you continued without confirming, so it was not run.)_';
        $last->set('aim_content', $txt !== '' ? $txt . "\n\n" . $note : $note);
        $last->set('aim_pending_action', null);
        $last->save();
    }

    public static function decodeTrace($value): array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }
}
