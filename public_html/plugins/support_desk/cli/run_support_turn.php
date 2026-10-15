<?php
/**
 * CLI worker for one support turn: `php run_support_turn.php <message_id>`.
 *
 * Spawned by the ask action (ChatWorkerSpawner::spawnWorker) so the request
 * returns a poll handle at once and the turn runs in its own process. Loads
 * the assistant placeholder, its transcript and its thread, and finishes the
 * row in place (running → complete | cancelled | failed); the site's panel
 * polls it.
 *
 * CLI only.
 *
 * @version 0.1.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo "CLI only\n";
	exit(1);
}
if ($argc < 2 || (int)$argv[1] <= 0) {
	fwrite(STDERR, "Usage: php run_support_turn.php <message_id>\n");
	exit(2);
}
$message_id = (int)$argv[1];

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));

$msg = new AiConversationMessage($message_id, true);
if (!$msg->key || $msg->get('aim_role') !== AiConversationMessage::ROLE_ASSISTANT) {
	fwrite(STDERR, "Assistant message #$message_id not found.\n");
	exit(3);
}
$conversation = new AiConversation((int)$msg->get('aim_aic_conversation_id'), true);
$thread_id = (int)$conversation->get('aic_support_thread_id');
if (!$conversation->key || $thread_id <= 0) {
	fwrite(STDERR, "Message #$message_id is not in a support thread.\n");
	exit(3);
}
$thread = new SupportThread($thread_id, true);
if (!$thread->key) {
	fwrite(STDERR, "Support thread #$thread_id not found.\n");
	exit(3);
}

set_time_limit(0);
ignore_user_abort(true);

try {
	SupportTurn::runAndFinalize($thread, $msg);
} catch (\Throwable $e) {
	ChatAsync::log('[support cli] uncaught for message #' . $message_id . ': ' . get_class($e) . ': ' . $e->getMessage());
	try { SupportTurn::markFailed($msg, 'The support desk could not answer this one. Try again, or ask for a person.'); } catch (\Throwable $e2) {}
}
exit(0);
