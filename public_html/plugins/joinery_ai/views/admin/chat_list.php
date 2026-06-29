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
    $title = trim((string)$c->get('aic_title'));
    if ($title === '') $title = 'Untitled';
    $out[] = [
        'id'     => (int)$c->key,
        'title'  => $title,
        'pinned' => (bool)$c->get('aic_pinned'),
    ];
}

echo json_encode(['success' => true, 'conversations' => $out]);
exit;
