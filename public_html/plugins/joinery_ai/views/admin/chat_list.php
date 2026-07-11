<?php
/**
 * Joinery AI — filtered thread list (AJAX, JSON).
 * URL: /admin/joinery_ai/chat_list?search=term  (GET)
 *
 * Backs the live search box in the thread pane. Returns the caller's own
 * conversations, pinned-first then most-recent, optionally filtered by a term
 * matched against the title OR any message body in the thread:
 *   { success, conversations: [ { id, title, pinned } ] }
 *
 * With no `search`, returns the same list the page renders on load. Owner-scoped
 * exactly like the other chat endpoints.
 */
header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSerializer.php'));

$session = SessionControl::get_instance();
// Any logged-in user; the /admin/* route is permission-gated (5). Also backs
// /profile/joinery_ai/ for members, whose reads are owner-scoped.
if (!$session->is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}
$uid = (int)$session->get_user_id();

$search = trim((string)LibraryFunctions::fetch_variable_local($_GET, 'search', ''));

$options = ['owner_user_id' => $uid, 'deleted' => false];
if ($search !== '') $options['search'] = $search;

// The non-search path orders via the constructor's sort arg; the search path
// orders inside its own query. Pass the same sort either way.
$conversations = new MultiAiConversation(
    $options,
    ['aic_pinned' => 'DESC', 'aic_update_time' => 'DESC']
);
$conversations->load();

$out = [];
foreach ($conversations as $c) {
    // conversationSummary withholds the title (placeholder + locked flag) for a
    // locked protected chat, so the list still renders while locked.
    $out[] = ChatSerializer::conversationSummary($c);
}

$response = ['success' => true, 'conversations' => $out];
// Searching while the vault is locked can't reach protected chats — flag it so
// the client offers to unlock and re-search.
if ($search !== '' && MultiAiConversation::ownerHasLockedProtected($uid)) {
    $response['search_locked'] = true;
}

echo json_encode($response);
exit;
