<?php
/**
 * Joinery AI — export a chat thread (AJAX, JSON).
 * URL: /admin/joinery_ai/chat_export?conversation_id=N  (GET)
 *
 * Returns both export flavors in one round trip so the pane's Export dialog can
 * Copy (to clipboard) or Download (as a Blob) either one without a second call
 * and without losing the click's user-activation for the clipboard write:
 *   { success, title, markdown, text }
 *
 * Owner-scoped exactly like the other chat endpoints: the conversation must
 * belong to the current user and not be deleted.
 */
header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatExport.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));

function chat_export_fail(string $msg): void {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

$session = SessionControl::get_instance();
// Any logged-in user; the /admin/* route is permission-gated (5). Also backs
// /profile/joinery_ai/ for members, whose reads are owner-scoped.
if (!$session->is_logged_in()) {
    chat_export_fail('Not authorized.');
}
$uid = (int)$session->get_user_id();

$conversation_id = (int)LibraryFunctions::fetch_variable_local($_GET, 'conversation_id', 0);

$conversation = new AiConversation($conversation_id, true);
if (!$conversation->key
        || (int)$conversation->get('aic_owner_user_id') !== $uid
        || $conversation->get('aic_delete_time')) {
    chat_export_fail('Conversation not found.');
}

// Locked-state contract: an export IS a content read (title + every message
// decrypt) — a locked protected chat prompts unlock instead of assembling.
function chat_export_locked(): void {
    echo json_encode(['success' => false, 'locked' => true,
        'message' => 'Unlock your vault to export this chat.']);
    exit;
}
if (ChatSeal::isLocked($conversation)) {
    chat_export_locked();
}

$rows = new MultiAiConversationMessage(
    ['conversation_id' => (int)$conversation->key, 'deleted' => false],
    ['aim_message_id' => 'ASC']
);
$rows->load();
$messages = [];
foreach ($rows as $row) $messages[] = $row;

try {
    $export = ChatExport::assemble($conversation, $messages);
} catch (VaultLockedException $e) {
    // The window closed between the check above and the reads.
    chat_export_locked();
}

echo json_encode([
    'success'  => true,
    'title'    => $export['title'],
    'markdown' => $export['markdown'],
    'text'     => $export['text'],
]);
exit;
