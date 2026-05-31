<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_agent_file_edit_logic.php'));

$page_vars = process_logic(admin_agent_file_edit_logic(array_merge($_GET, $_POST)));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'     => 'system-agent-files',
	'breadcrumbs' => array(
		'System'      => '',
		'Agent Files' => '/admin/admin_agent_files',
		($agent_file->key ? 'Edit' : 'New') . ' Agent File' => '',
	),
	'session' => $session,
));

if (!empty($error)) {
	echo '<div class="alert alert-danger" role="alert">' . htmlspecialchars($error) . '</div>';
}

$pageoptions = array('title' => ($agent_file->key ? 'Edit Agent File' : 'New Agent File'));
$page->begin_box($pageoptions);

echo '<p style="margin-bottom: 16px; color: #555;">'
	. 'This row is the source of truth for its target file(s). Click <strong>Save</strong> to persist changes to the database, '
	. '<strong>Save &amp; Write to disk</strong> to also flush the file to the project root, or <strong>Delete</strong> to soft-delete the row and remove its on-disk files.'
	. '</p>';

$targets_for_textarea = '';
if ($agent_file->key) {
	$targets_for_textarea = implode("\n", $agent_file->get_target_filenames_array());
}

$override_values = array(
	'agf_target_filenames' => $targets_for_textarea,
);

$formwriter = $page->getFormWriter('form1', array(
	'model'                  => $agent_file,
	'values'                 => $override_values,
	'edit_primary_key_value' => $agent_file->key,
));

echo $formwriter->begin_form();

$formwriter->textinput('agf_name', 'Name', array(
	'maxlength'  => 255,
	'validation' => array('required' => true),
	'help'       => 'Human-readable label, e.g. "Internal CLAUDE.md" or "Customer baseline".',
));

$formwriter->textbox('agf_target_filenames', 'Target filenames (one per line)', array(
	'rows'    => 4,
	'cols'    => 60,
	'htmlmode' => 'no',
	'help'    => 'Filenames written to project root, one per line. Examples: CLAUDE.md, GEMINI.md, AGENTS.md. No directory separators.',
));

$formwriter->textbox('agf_content', 'Content', array(
	'rows'    => 30,
	'cols'    => 100,
	'htmlmode' => 'no',
	'help'    => 'Full file body. Markdown is typical but any text content works.',
));

$formwriter->submitbutton('btn_submit', 'Save');
echo ' ';
$formwriter->submitbutton('btn_save_and_write', 'Save & Write to disk');

if ($agent_file->key) {
	echo ' ';
	$formwriter->submitbutton('btn_delete', 'Delete', array(
		'class'          => 'btn btn-danger',
		'onclick'        => "return confirm('Soft-delete this row and remove its on-disk target files?');",
		'formnovalidate' => true,
	));
}

echo $formwriter->end_form();

$page->end_box();
$page->admin_footer();
?>
