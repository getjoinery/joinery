<?php
/**
 * Agent File Compare — side-by-side read-only view of an active row's
 * content next to its pending upgrade candidate's content. Two
 * <textarea readonly>s, no diff library.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('data/agent_files_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);

$active_id = isset($_GET['active_id']) ? (int)$_GET['active_id'] : 0;
$page = new AdminPage();
$page->admin_header(array(
	'menu-id'     => 'system-agent-files',
	'breadcrumbs' => array(
		'System'      => '',
		'Agent Files' => '/admin/admin_agent_files',
		'Compare'     => '',
	),
	'session' => $session,
));

if ($active_id <= 0) {
	echo '<div class="alert alert-danger">Missing active_id.</div>';
	$page->admin_footer();
	return;
}

try {
	$active = new AgentFile($active_id, TRUE);
} catch (\Throwable $e) {
	echo '<div class="alert alert-danger">Agent file not found.</div>';
	$page->admin_footer();
	return;
}

$candidate = $active->current_candidate();
if (!$candidate) {
	echo '<div class="alert alert-warning">No pending upgrade candidate for this row.</div>';
	echo '<a class="btn btn-secondary" href="/admin/admin_agent_files">Back</a>';
	$page->admin_footer();
	return;
}

$active_content = (string)$active->get('agf_content');
$candidate_content = (string)$candidate->get('agf_content');

echo '<p>Comparing <strong>' . htmlspecialchars($active->get('agf_name') ?? '(unnamed)')
	. '</strong> (your current content) against the pending upgrade candidate.</p>';

echo '<div class="row" style="margin-bottom:12px;">';
echo '<div class="col-md-6">';
echo '<h6>Current (active)</h6>';
echo '<textarea readonly class="form-control" style="font-family:monospace;font-size:12px;height:600px;white-space:pre;">'
	. htmlspecialchars($active_content) . '</textarea>';
echo '</div>';
echo '<div class="col-md-6">';
echo '<h6>Upgrade candidate</h6>';
echo '<textarea readonly class="form-control" style="font-family:monospace;font-size:12px;height:600px;white-space:pre;">'
	. htmlspecialchars($candidate_content) . '</textarea>';
echo '</div>';
echo '</div>';

echo '<form method="POST" action="/admin/admin_agent_files" style="display:inline;" '
	. 'onsubmit="return confirm(\'Switch to the upgrade candidate? The current content will be preserved as an archived row.\');">';
echo '<input type="hidden" name="action" value="switch_to_candidate">';
echo '<input type="hidden" name="agf_agent_file_id" value="' . (int)$active->key . '">';
echo '<button type="submit" class="btn btn-primary">Switch to new version</button>';
echo '</form> ';
echo '<a class="btn btn-secondary" href="/admin/admin_agent_files">Back</a>';

$page->admin_footer();
?>
