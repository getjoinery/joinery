<?php
/**
 * CLI worker for a single Joinery AI chat turn.
 *
 * Spawned via `php run_chat_turn.php <message_id> [decision]` by the /api/v1
 * chat actions (ChatWorkerSpawner) so the request returns a poll handle
 * immediately and the turn runs in its own process — the same detached-worker
 * mechanism recipe runs use. Loads the assistant placeholder row and its
 * conversation, then finalizes it in place (running → complete | failed) so the
 * client's chat_poll surfaces the result.
 *
 *   no decision       → run a fresh turn (ChatTurn::runAndFinalize)
 *   confirm | cancel   → resume from a proposed action (ChatTurn::resumeAndFinalize)
 *
 * CLI-only — bails immediately if invoked over HTTP.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php run_chat_turn.php <message_id> [decision]\n");
    exit(2);
}

$message_id = (int)$argv[1];
$decision   = isset($argv[2]) ? (string)$argv[2] : '';
if ($message_id <= 0) {
    fwrite(STDERR, "Invalid message_id: '$argv[1]'\n");
    exit(2);
}

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatTurn.php'));

$msg = new AiConversationMessage($message_id, true);
if (!$msg->key) {
    fwrite(STDERR, "AiConversationMessage #$message_id not found.\n");
    exit(3);
}
$conversation = new AiConversation((int)$msg->get('aim_aic_conversation_id'), true);
if (!$conversation->key) {
    fwrite(STDERR, "Parent conversation for message #$message_id not found.\n");
    exit(3);
}
$uid = (int)$conversation->get('aic_owner_user_id');

// Long-running task; the runner has its own internal per-turn ceiling.
set_time_limit(0);
ignore_user_abort(true);

try {
    if ($decision === 'confirm' || $decision === 'cancel') {
        $pending = $msg->get('aim_pending_action');
        if (is_string($pending)) $pending = json_decode($pending, true);
        if (empty($pending) || !is_array($pending)) {
            ChatTurn::markFailed($msg, 'There is no pending action to resolve.');
            exit(0);
        }
        $lead_text = (string)$msg->get('aim_content');
        ChatTurn::resumeAndFinalize($conversation, $uid, $msg, $pending, $lead_text, $decision);
    } else {
        ChatTurn::runAndFinalize($conversation, $uid, $msg);
    }
} catch (Throwable $e) {
    error_log('[joinery_ai chat cli] uncaught for message #' . $message_id . ': ' . $e->getMessage());
    try { ChatTurn::markFailed($msg, 'The assistant could not complete this turn.'); } catch (Throwable $e2) {}
}

exit(0);
