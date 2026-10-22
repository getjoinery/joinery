<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_utilities_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$page_vars = process_logic(admin_utilities_logic(array_merge($_GET, $_POST)));

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
// Buttons (POST), not links: a link is a GET any other site can make the browser follow.
echo '<div style="display: flex; flex-direction: column; align-items: flex-start; gap: 6px;">';
echo AdminPage::action_button('Update database', '/utils/update_database', array('class' => 'btn btn-secondary'));
echo AdminPage::action_button('Update database (verbose)', '/utils/update_database', array(
	'hidden' => array('verbose' => 1), 'class' => 'btn btn-secondary'));
echo AdminPage::action_button('Upgrade database (fix mismatched column types)', '/utils/update_database', array(
	'hidden' => array('upgrade' => 1), 'class' => 'btn btn-secondary'));
echo AdminPage::action_button('Cleanup database (delete unused fields)', '/utils/update_database', array(
	'hidden' => array('cleanup' => 1), 'class' => 'btn btn-danger',
	'confirm' => 'Delete every column no data class declares? Their data is gone for good.', 'confirm_style' => 'danger'));
echo '</div>';

$page->end_box();

$page->admin_footer();

?>
