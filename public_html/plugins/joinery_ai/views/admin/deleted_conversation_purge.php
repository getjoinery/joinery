<?php
/**
 * Joinery AI - Purge Deleted Conversation
 * URL: /admin/joinery_ai/deleted_conversation_purge?aic_conversation_id=N
 *
 * Dry-run preview + confirm for permanently deleting one soft-deleted
 * conversation (cascades through its messages, attachment links, and the
 * uploaded Files they point at).
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/logic/admin_deleted_conversation_purge_logic.php'));

$page_vars = process_logic(admin_joinery_ai_deleted_conversation_purge_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header([
    'menu-id' => 'joinery-ai-deleted',
    'page_title' => 'Purge Conversation',
    'readable_title' => 'Purge Conversation',
    'breadcrumbs' => [
        'Joinery AI' => '/admin/joinery_ai',
        'Deleted Chats' => '/admin/joinery_ai/deleted_conversations',
        'Purge' => '',
    ],
    'session' => $session,
]);

$pageoptions['title'] = 'Deletion Impact Preview';
$page->begin_box($pageoptions);

echo '<div class="fields full">';
echo '<p><strong>Total records that will be affected: ' . intval($dry_run['total_affected']) . '</strong></p>';

if (!$dry_run['can_delete']) {
    echo '<div class="alert alert-danger">';
    echo '<strong>Cannot Delete:</strong><ul>';
    foreach ($dry_run['blocking_reasons'] as $reason) {
        echo '<li>' . htmlspecialchars($reason) . '</li>';
    }
    echo '</ul></div>';
} else {
    echo '<table class="table table-striped">';
    echo '<thead><tr><th>Table</th><th>Column</th><th>Action</th><th>Count</th><th>Details</th></tr></thead>';
    echo '<tbody>';

    echo '<tr class="table-warning">';
    echo '<td><strong>' . htmlspecialchars($dry_run['primary']['table']) . '</strong></td>';
    echo '<td>' . htmlspecialchars($dry_run['primary']['key_column']) . '</td>';
    echo '<td><span class="badge bg-danger">DELETE</span></td>';
    echo '<td>1</td>';
    // A sealed title is the owner's protected content — get() would throw
    // VaultLockedException in the admin's session. Placeholder, never content.
    $purge_title = $conversation->get('aic_content_sealed')
        ? 'Protected chat (sealed)'
        : ($conversation->get('aic_title') ?: '(untitled)');
    echo '<td>' . htmlspecialchars($purge_title) . ' (ID: ' . intval($dry_run['primary']['key']) . ')</td>';
    echo '</tr>';

    foreach ($dry_run['dependencies'] as $dep) {
        $badge_class = match($dep['action']) {
            'cascade', 'permanent_delete' => 'bg-danger',
            'set_value' => 'bg-warning',
            'null' => 'bg-info',
            'prevent' => 'bg-secondary',
            default => 'bg-secondary'
        };
        $badge_label = match($dep['action']) {
            'permanent_delete' => 'RECURSIVE DELETE',
            default => strtoupper($dep['action'])
        };

        echo '<tr>';
        echo '<td>' . htmlspecialchars($dep['table']) . '</td>';
        echo '<td>' . htmlspecialchars($dep['column']) . '</td>';
        echo '<td><span class="badge ' . $badge_class . '">' . $badge_label . '</span></td>';
        echo '<td>' . intval($dep['count']) . '</td>';
        echo '<td>';
        if ($dep['action'] === 'set_value') {
            echo 'Set to: ' . htmlspecialchars($dep['action_value'] ?? 'NULL');
        } elseif ($dep['action'] === 'null') {
            echo 'Set to NULL';
        } elseif ($dep['action'] === 'cascade' || $dep['action'] === 'permanent_delete') {
            echo 'Will be permanently deleted';
        }
        if (!empty($dep['message'])) {
            echo '<br><em>' . htmlspecialchars($dep['message']) . '</em>';
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}
echo '</div>';
$page->end_box();

if ($dry_run['can_delete']) {
    $pageoptions['title'] = 'Confirm Purge';
    $page->begin_box($pageoptions);

    $formwriter = $page->getFormWriter('form1');
    echo $formwriter->begin_form();

    echo '<div class="fields full">';
    echo '<p><strong>WARNING:</strong> This will permanently delete this conversation and affect '
       . intval($dry_run['total_affected']) . ' records as shown above, including any uploaded files.</p>';

    $formwriter->hiddeninput('aic_conversation_id', '', ['value' => $aic_conversation_id]);
    $formwriter->hiddeninput('confirm', '', ['value' => 1]);

    $formwriter->submitbutton('btn_purge', 'Permanently Delete Conversation', ['class' => 'btn-danger']);
    echo ' <a href="/admin/joinery_ai/deleted_conversations" class="btn btn-secondary">Cancel</a>';

    echo '</div>';
    echo $formwriter->end_form();

    $page->end_box();
}

$page->admin_footer();
