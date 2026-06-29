<?php
/**
 * Joinery AI - Chat (admin)
 * URL: /admin/joinery_ai/chat
 *
 * Two-pane interactive assistant: conversation list on the left, transcript +
 * composer + inline confirmation cards on the right. The markup + behavior live
 * in the shared body partial (includes/chat_view_body.php); this view only
 * supplies the admin page shell and the /admin/joinery_ai/ endpoint base. The
 * member-facing copy at /profile/joinery_ai/chat reuses the same body.
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/logic/admin_chat_logic.php'));

$page_vars = process_logic(admin_joinery_ai_chat_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header([
    'menu-id' => 'joinery-ai-chat',
    'page_title' => 'Joinery AI Chat',
    'readable_title' => 'Chat',
    'breadcrumbs' => [
        'Joinery AI' => '/admin/joinery_ai',
        'Chat' => '',
    ],
    'session' => $session,
]);

$base = '/admin/joinery_ai/';
require(PathHelper::getIncludePath('plugins/joinery_ai/includes/chat_view_body.php'));

$page->admin_footer();
