<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRunner.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatAsync.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
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
        $sealed = ChatSeal::isProtectedLevel($conversation->get('aic_security_level'));
        $stamper = ChatAsync::activityStamper($assistant_msg);
        $stamper('Starting…');
        $unreachable = self::reachabilityError($conversation);
        if ($unreachable !== null) {
            error_log('[joinery_ai chat] preflight unreachable: ' . $unreachable);
            self::markFailed($assistant_msg, LlmProviderException::friendlyMessage('api_network_error'));
            return;
        }
        try {
            $turn = ChatRunner::runTurn($conversation, $uid, (int)$assistant_msg->key,
                ChatAsync::streamSink($assistant_msg, '', $sealed,
                    (int)$conversation->get('aic_owner_user_id')), $stamper);
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

        // Content columns: json-encode the trace and seal every content column when
        // protected (Standard stores plaintext). Persist via a targeted raw UPDATE
        // — a sealed row must never be save()d (that would decrypt-and-rewrite,
        // unsealing it or throwing when locked).
        $cols = ChatSeal::turnColumns($conversation, (int)$assistant_msg->key,
            ChatRunner::resolveAssistantText($result),
            $ctx->toolCalls(),
            !empty($result['pending_action']) ? $result['pending_action'] : null);
        $cols['aim_input_tokens']  = (int)$result['input_tokens'];
        $cols['aim_output_tokens'] = (int)$result['output_tokens'];
        if (isset($result['context_window']) && $result['context_window'] !== null) {
            $cols['aim_context_window'] = (int)$result['context_window'];
        }
        // A user Cancel (between steps or mid-stream) funnels to stop_reason
        // 'cancelled' — persist the terminal CANCELLED state, keeping whatever
        // partial answer already streamed (resolveAssistantText returns it, or a
        // short "Cancelled" marker when nothing streamed).
        $cols['aim_status']        = (($result['stop_reason'] ?? '') === 'cancelled')
            ? AiConversationMessage::STATUS_CANCELLED
            : AiConversationMessage::STATUS_COMPLETE;
        $cols['aim_activity']      = null;
        // Clear the request flag on every finalize path so a cancel that arrived
        // just after the turn settled never leaves a stale "please stop" on the row.
        $cols['aim_cancel_requested'] = false;
        AiConversationMessage::updateColumns((int)$assistant_msg->key, $cols);
        ChatAsync::clearScratch((int)$assistant_msg->key);   // the sealed content now lives in aim_content

        self::rollupUsage($conversation, (int)$result['input_tokens'], (int)$result['output_tokens']);
    }

    /**
     * Continue the turn from a confirmation decision and fold the resumed reply
     * into the same assistant row (one assistant message per turn). Any failure
     * marks the row FAILED with an error the poller surfaces. Never echoes.
     */
    public static function resumeAndFinalize(AiConversation $conversation, int $uid,
            AiConversationMessage $msg, array $pending, string $lead_text, string $decision): void {
        $sealed = ChatSeal::isProtectedLevel($conversation->get('aic_security_level'));
        $stamper = ChatAsync::activityStamper($msg);
        $stamper('Resuming…');
        $unreachable = self::reachabilityError($conversation);
        if ($unreachable !== null) {
            error_log('[joinery_ai chat] resume preflight unreachable: ' . $unreachable);
            self::markFailed($msg, LlmProviderException::friendlyMessage('api_network_error'));
            return;
        }
        try {
            $seed = $lead_text !== '' ? $lead_text . "\n\n" : '';
            $sink = ChatAsync::streamSink($msg, $seed, $sealed,
                (int)$conversation->get('aic_owner_user_id'));
            $turn = ChatRunner::resumeTurn($conversation, $uid, $pending, $lead_text, $decision,
                (int)$msg->key, $sink, $stamper);
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

        // The existing trace + tokens decrypt/read in-window BEFORE turnColumns
        // re-seals the content under a fresh DEK.
        $merged_trace = array_merge(self::decodeTrace($msg->get('aim_tool_calls')), $ctx->toolCalls());
        $prior_in  = (int)$msg->get('aim_input_tokens');
        $prior_out = (int)$msg->get('aim_output_tokens');

        $cols = ChatSeal::turnColumns($conversation, (int)$msg->key, $combined, $merged_trace,
            !empty($result['pending_action']) ? $result['pending_action'] : null);
        $cols['aim_input_tokens']  = $prior_in + (int)$result['input_tokens'];
        $cols['aim_output_tokens'] = $prior_out + (int)$result['output_tokens'];
        if (isset($result['context_window']) && $result['context_window'] !== null) {
            $cols['aim_context_window'] = (int)$result['context_window'];   // fresh window for the resumed turn
        }
        // A cancel during the resumed generation is terminal too — keep the
        // combined lead-text + partial resumed answer (already folded into
        // $combined) and mark CANCELLED rather than mislabelling it complete.
        $cols['aim_status']        = (($result['stop_reason'] ?? '') === 'cancelled')
            ? AiConversationMessage::STATUS_CANCELLED
            : AiConversationMessage::STATUS_COMPLETE;
        $cols['aim_activity']      = null;
        $cols['aim_cancel_requested'] = false;   // clear on every finalize path (§5)
        AiConversationMessage::updateColumns((int)$msg->key, $cols);
        ChatAsync::clearScratch((int)$msg->key);

        self::rollupUsage($conversation, (int)$result['input_tokens'], (int)$result['output_tokens']);
    }

    /** Add a turn's tokens to the conversation totals and bump its update time.
     *  Token/time columns are cleartext; a targeted UPDATE leaves the sealed
     *  title/instructions untouched (a full save() would decrypt-and-rewrite them). */
    private static function rollupUsage(AiConversation $conversation, int $in, int $out): void {
        AiConversation::updateColumns((int)$conversation->key, [
            'aic_total_input_tokens'  => (int)$conversation->get('aic_total_input_tokens') + $in,
            'aic_total_output_tokens' => (int)$conversation->get('aic_total_output_tokens') + $out,
            'aic_update_time'         => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * A fast pre-turn reachability probe of the conversation's provider, so an
     * offline or sleeping local model server fails the turn in a couple of seconds
     * instead of stalling the full streaming call. Returns the probe's diagnostic
     * message when the host is unreachable, or null when reachable / no probe
     * applies (cloud providers). A provider that can't even be constructed returns
     * null — the real call's exception path surfaces that with its own message.
     */
    private static function reachabilityError(AiConversation $conversation): ?string {
        try {
            return LlmProviderFactory::forConversation($conversation)->reachabilityProbe();
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function markFailed(AiConversationMessage $msg, string $error): void {
        // Seal the error on a protected conversation (it may echo provider detail);
        // errorColumns resolves the conversation itself and no-ops for Standard.
        $cols = ChatSeal::errorColumns($msg, $error);
        $cols['aim_status']   = AiConversationMessage::STATUS_FAILED;
        $cols['aim_activity'] = null;
        $cols['aim_cancel_requested'] = false;   // no stale flag on a settled row (§5)
        AiConversationMessage::updateColumns((int)$msg->key, $cols);
        ChatAsync::clearScratch((int)$msg->key);
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

        // get() decrypts the prior content in-window on a protected row; re-seal
        // the amended content under the SAME per-message DEK, and clear the pending
        // action (null needs no seal). Persist via a targeted UPDATE.
        $txt = (string)$last->get('aim_content');
        $note = '_(Proposed an action; you continued without confirming, so it was not run.)_';
        $cols = ChatSeal::resealMessageColumn($last, $conversation, 'aim_content',
            $txt !== '' ? $txt . "\n\n" . $note : $note);
        $cols['aim_pending_action'] = null;
        AiConversationMessage::updateColumns((int)$last->key, $cols);
    }

    public static function decodeTrace($value): array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }
}
