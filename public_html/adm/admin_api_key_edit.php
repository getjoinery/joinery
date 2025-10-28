<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_api_key_edit_logic.php'));

	$page_vars = process_logic(admin_api_key_edit_logic($_GET, $_POST));
	extract($page_vars);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'api_keys',
		'breadcrumbs' => array(
			'ApiKeys'=>'/admin/admin_api_keys',
			'Edit ApiKey' => '',
		),
		'session' => $session,
	)
	);

	$pageoptions['title'] = "Edit ApiKey";
	$page->begin_box($pageoptions);

	// Editing an existing API key
	$formwriter = $page->getFormWriter('form1', 'v2', [
		'model' => $api_key,
		'edit_primary_key_value' => $api_key->key
	]);

	echo $formwriter->begin_form();

	$formwriter->textinput('apk_name', 'Key name');

	$formwriter->dropinput('apk_is_active', 'Active', [
		'options' => ['No' => 0, 'Yes' => 1]
	]);

	$formwriter->dropinput('apk_permission', 'Permission', [
		'options' => ['Read only' => 1, 'Write only' => 2, 'Read/Write' => 3, 'Read/Write/Delete' => 4]
	]);

	$formwriter->textinput('apk_ip_restriction', 'Allowed IP addresses (comma separated) (optional)');

	$formwriter->datetimeinput('apk_start_time', 'Key start time (optional)');

	$formwriter->datetimeinput('apk_expires_time', 'Key expires time (optional)');

	$formwriter->submitbutton('btn_submit', 'Submit');

	echo $formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

?>
