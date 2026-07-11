<?php
/**
 * Joinery AI - Deleted Chats
 * URL: /admin/joinery_ai/deleted_conversations
 *
 * Superadmin purge tool: lists every soft-deleted conversation, across all
 * owners, with a per-row link to the dry-run confirm page plus a bulk
 * "Empty Trash" action.
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/logic/admin_deleted_conversations_logic.php'));

$page_vars = process_logic(admin_joinery_ai_deleted_conversations_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header([
    'menu-id' => 'joinery-ai-deleted',
    'page_title' => 'Deleted Chats',
    'readable_title' => 'Deleted Chats',
    'breadcrumbs' => [
        'Joinery AI' => '/admin/joinery_ai',
        'Deleted Chats' => '',
    ],
    'session' => $session,
]);

if ($numrecords) {
    echo '<form method="post" action="/admin/joinery_ai/deleted_conversations" class="mb-3" onsubmit="return confirm(\'Permanently delete all ' . intval($numrecords) . ' soft-deleted conversations? This cannot be undone.\')">';
    echo '<input type="hidden" name="action" value="empty_trash">';
    echo '<input type="hidden" name="confirm" value="1">';
    echo '<button type="submit" class="btn btn-sm btn-danger">Empty Trash (' . intval($numrecords) . ')</button>';
    echo '</form>';
}

$pager = new Pager(['numrecords' => $numrecords, 'numperpage' => $numperpage]);
$headers = ['Owner', 'Title', 'Deleted', 'Actions'];
$page->tableheader($headers, ['title' => 'Deleted Chats (' . $numrecords . ')'], $pager);

foreach ($conversations as $conversation) {
    $row = [];

    $owner = new User((int)$conversation->get('aic_owner_user_id'), TRUE);
    $row[] = $owner->key ? htmlspecialchars($owner->display_name()) : '<em class="text-muted">unknown</em>';

    // A sealed title is another user's protected content — get() would try to
    // decrypt with the OWNER's vault window (never open in an admin's session)
    // and throw VaultLockedException. Show a placeholder, never the content.
    $row[] = $conversation->get('aic_content_sealed')
        ? '<em class="text-muted">Protected chat (sealed)</em>'
        : htmlspecialchars($conversation->get('aic_title') ?: '(untitled)');

    $when = $conversation->get('aic_delete_time')
        ? LibraryFunctions::convert_time(
            $conversation->get('aic_delete_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A'
          )
        : '-';
    $row[] = htmlspecialchars($when);

    $row[] = '<a class="btn btn-sm btn-outline-danger" href="/admin/joinery_ai/deleted_conversation_purge?aic_conversation_id='
           . (int)$conversation->key . '">Purge</a>';

    $page->disprow($row);
}

if (!count($conversations)) {
    echo '<tr><td colspan="4" class="text-center text-muted py-4">No deleted conversations.</td></tr>';
}

$page->endtable($pager);
$page->admin_footer();
