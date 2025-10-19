<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_utilities_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$page_vars = process_logic(admin_utilities_logic($_GET, $_POST));

$session = $page_vars['session'];

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> NULL,
	'page_title' => 'Utilities',
	'readable_title' => 'Utilities',
	'breadcrumbs' => array(
		'Utilities'=>'',
	),
	'session' => $session,
)
);

$pageoptions['title'] = "Utilities";
$page->begin_box($pageoptions);

echo '<h3>General Utilities</h3>';

echo '<h4>Update database script</h4>';
echo '<a href="/utils/update_database">Update database</a><br>';
echo '<a href="/utils/update_database?verbose=1">Update database (verbose)</a><br>';
echo '<a href="/utils/update_database">Upgrade database (fix mismatched column types)</a><br>';
echo '<a href="/utils/update_database">Cleanup database (delete unused fields)</a><br>';

$page->end_box();

$page->admin_footer();

?>
