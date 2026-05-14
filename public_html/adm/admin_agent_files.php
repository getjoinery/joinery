<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_agent_files_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$page_vars = process_logic(admin_agent_files_logic($_GET, $_POST));

$session     = $page_vars['session'];
$agent_files = $page_vars['agent_files'];
$numrecords  = $page_vars['numrecords'];
$numperpage  = $page_vars['numperpage'];
$written     = $page_vars['written'];
$error       = $page_vars['error'];

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'     => 'system-agent-files',
	'breadcrumbs' => array(
		'System'      => '',
		'Agent Files' => '',
	),
	'session' => $session,
));

if ($error) {
	echo '<div class="alert alert-danger" role="alert">' . htmlspecialchars($error) . '</div>';
}
if ($written) {
	echo '<div class="alert alert-success" role="alert">Agent file written to disk.</div>';
}

$headers = array('Name', 'Target Filenames', 'Last Written', 'Status', 'Actions');
$altlinks = array('New Agent File' => '/admin/admin_agent_file_edit');
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));
$table_options = array(
	'altlinks' => $altlinks,
	'title'    => 'Agent Files',
);
$page->tableheader($headers, $table_options, $pager);

foreach ($agent_files as $agent_file) {
	$rowvalues = array();

	$name_link = "<a href='/admin/admin_agent_file_edit?agf_agent_file_id=" . (int)$agent_file->key . "'>"
		. htmlspecialchars($agent_file->get('agf_name') ?? '(unnamed)') . "</a>";
	$rowvalues[] = $name_link;

	$targets = $agent_file->get_target_filenames_array();
	$rowvalues[] = empty($targets)
		? '<em>none</em>'
		: htmlspecialchars(implode(', ', $targets));

	$last_written = $agent_file->get('agf_last_written_time');
	$rowvalues[] = $last_written
		? htmlspecialchars(LibraryFunctions::convert_time($last_written, 'UTC', $session->get_timezone(), 'M j, Y g:i A T'))
		: '<em>never</em>';

	$status = $agent_file->disk_sync_status();
	$status_labels = array(
		'matches' => '<span style="color:#2a8a2a;">✓ in sync</span>',
		'differs' => '<span style="color:#b8860b;">⚠ differs</span>',
		'missing' => '<span style="color:#a00;">✗ missing on disk</span>',
		'never'   => '<span style="color:#888;">— never written</span>',
	);
	$rowvalues[] = $status_labels[$status] ?? htmlspecialchars($status);

	$actions = '';
	if (!empty($targets)) {
		$actions .= '<form method="POST" action="/admin/admin_agent_files" style="display:inline; margin-right: 6px;">'
			. '<input type="hidden" name="action" value="write_to_disk">'
			. '<input type="hidden" name="agf_agent_file_id" value="' . (int)$agent_file->key . '">'
			. '<button type="submit" class="btn btn-sm btn-primary">Write to disk</button>'
			. '</form>';
	}
	$actions .= '<a class="btn btn-sm btn-secondary" href="/admin/admin_agent_file_edit?agf_agent_file_id=' . (int)$agent_file->key . '">Edit</a>';
	$rowvalues[] = $actions;

	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
?>
