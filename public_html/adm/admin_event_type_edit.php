<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_event_type_edit_logic.php'));

$page_vars = process_logic(admin_event_type_edit_logic($_GET, $_POST));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id'=> 'events',
		'page_title' => 'Event Types',
		'readable_title' => 'Event Types',
		'breadcrumbs' => array(
			'Events'=>'/admin/admin_events',
			'Event Types' => '',
		),
		'session' => $session,
	)
);

$options['title'] = 'Edit Event Type';
$page->begin_box($options);

// FormWriter V2 with model and edit_primary_key_value
$formwriter = $page->getFormWriter('form1', [
	'model' => $event_type,
	'edit_primary_key_value' => $event_type->key
]);

$formwriter->begin_form();

$formwriter->textinput('ety_name', 'Event Type Name', [
	'validation' => ['required' => true, 'maxlength' => 255]
]);

$formwriter->submitbutton('btn_submit', 'Submit');
$formwriter->end_form();

$page->end_box();

$page->admin_footer();

?>
