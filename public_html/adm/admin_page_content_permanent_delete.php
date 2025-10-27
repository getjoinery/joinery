<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_page_content_permanent_delete_logic.php'));

$page_vars = process_logic(admin_page_content_permanent_delete_logic($_GET, $_POST));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'pages',
	'page_title' => 'Delete Page Content',
	'readable_title' => 'Delete Page Content',
	'breadcrumbs' => array(
		'Page Contents'=>'/admin/admin_page_contents',
		'Delete ' . $page_content->get('pac_location_name') => '',
	),
	'session' => $session,
)
);

$pageoptions['title'] = 'Delete Page Content '.$page_content->get('pac_location_name');
$page->begin_box($pageoptions);

$formwriter = $page->getFormWriter('form1', 'v2');
echo $formwriter->begin_form();

echo '<fieldset><h4>Confirm Delete</h4>';
	echo '<div class="fields full">';
	echo '<p>WARNING:  This will permanently delete this page_content ('.$page_content->get('pac_location_name') . ').</p>';

$formwriter->hiddeninput('confirm', ['value' => 1]);
$formwriter->hiddeninput('pac_page_content_id', ['value' => $pac_page_content_id]);

$formwriter->submitbutton('btn_submit', 'Submit');

	echo '</div>';
echo '</fieldset>';
echo $formwriter->end_form();

$page->end_box();

$page->admin_footer();
?>
