<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_agent_files_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$page_vars = process_logic(admin_agent_files_logic(array_merge($_GET, $_POST)));

$session     = $page_vars['session'];
$agent_files = $page_vars['agent_files'];
$numrecords  = $page_vars['numrecords'];
$numperpage  = $page_vars['numperpage'];
$written     = $page_vars['written'];
$switched    = $page_vars['switched'];
$error       = $page_vars['error'];
$confirm_row = $page_vars['confirm_row'];

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
if ($switched) {
	echo '<div class="alert alert-success" role="alert">Switched to upgrade candidate. The previously-active row is now archived.</div>';
}
if ($confirm_row) {
	$drifted = $confirm_row->get_drifted_targets();
	echo '<div class="alert alert-warning" role="alert">';
	echo '<strong>On-disk edits would be overwritten.</strong> ';
	echo 'These target file(s) for &ldquo;' . htmlspecialchars($confirm_row->get('agf_name') ?? '(unnamed)')
		. '&rdquo; have changed on disk since they were last written from the database: <strong>'
		. htmlspecialchars(implode(', ', $drifted)) . '</strong>.';
	echo '<p style="margin:8px 0 0;">Overwriting replaces them with the database content. The current '
		. 'on-disk copy of each file is saved next to it as <code>&lt;filename&gt;.old</code> before it is replaced.</p>';
	echo '<div style="margin-top:10px;">';
	echo '<form method="POST" action="/admin/admin_agent_files" style="display:inline; margin-right:8px;">'
		. '<input type="hidden" name="action" value="write_to_disk">'
		. '<input type="hidden" name="agf_agent_file_id" value="' . (int)$confirm_row->key . '">'
		. '<input type="hidden" name="force" value="1">'
		. '<button type="submit" class="btn btn-danger">Yes, overwrite</button>'
		. '</form>';
	echo '<a class="btn btn-secondary" href="/admin/admin_agent_files">Cancel</a>';
	echo '</div></div>';
}

$headers = array('Name', 'Target Filenames', 'Last Written', 'Disk Sync', 'Template Status', 'Actions');
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

	// Template Status column: baseline-hash state + candidate availability.
	$baseline_hash = $agent_file->get('agf_template_baseline_hash');
	$candidate     = $agent_file->current_candidate();
	$is_candidate  = $agent_file->get('agf_candidate_for') !== null;
	if ($is_candidate) {
		$template_cell = '<span class="badge bg-info text-dark">Candidate for #' . (int)$agent_file->get('agf_candidate_for') . '</span>';
	} elseif (!$baseline_hash) {
		$template_cell = '<span style="color:#888;">—</span>';
	} elseif ($agent_file->is_unmodified_from_baseline()) {
		$template_cell = $candidate
			? '<span class="badge bg-secondary">In sync</span>'
			: '<span class="badge bg-secondary">In sync</span>';
	} else {
		$template_cell = $candidate
			? '<span class="badge bg-warning text-dark">Edited &middot; Update available</span>'
			: '<span class="badge bg-warning text-dark">Edited</span>';
	}
	$rowvalues[] = $template_cell;

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

	// Inline panel for active rows with a pending upgrade candidate.
	if ($candidate && !$is_candidate) {
		echo '<tr><td colspan="' . count($headers) . '">';
		echo '<div class="alert alert-info" style="margin:8px 0;">';
		echo '<strong>An updated agent template is available.</strong> ';
		echo '<a class="btn btn-sm btn-outline-primary" href="/admin/admin_agent_file_compare?active_id=' . (int)$agent_file->key . '" style="margin-left:8px;">Compare</a> ';
		echo '<form method="POST" action="/admin/admin_agent_files" style="display:inline; margin-left:8px;" '
			. 'onsubmit="return confirm(\'Switch to the upgrade candidate? The current content will be preserved as an archived row.\');">';
		echo '<input type="hidden" name="action" value="switch_to_candidate">';
		echo '<input type="hidden" name="agf_agent_file_id" value="' . (int)$agent_file->key . '">';
		echo '<button type="submit" class="btn btn-sm btn-primary">Switch to new version</button>';
		echo '</form>';
		echo '</div>';
		echo '</td></tr>';
	}
}

$page->endtable($pager);
$page->admin_footer();
?>
