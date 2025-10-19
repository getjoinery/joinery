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

	// Editing an existing email
	$formwriter = $page->getFormWriter('form1');

	$validation_rules = array();
	$validation_rules['apk_name']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);

	echo $formwriter->begin_form('form', 'POST', '/admin/admin_api_key_edit');

	if($api_key->key){
		echo $formwriter->hiddeninput('apk_api_key_id', $api_key->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}

	echo $formwriter->textinput('Key name', 'apk_name', NULL, 100, $api_key->get('apk_name'), '', 255, '');

	$optionvals = array("Yes"=>1,"No"=>0);
	echo $formwriter->dropinput("Active", "apk_is_active", "", $optionvals, $api_key->get('apk_is_active'), '', FALSE);

	$optionvals = array("Read only"=>1, "Write only"=>2, "Read/Write"=>3, "Read/Write/Delete"=>4);
	echo $formwriter->dropinput("Permission", "apk_permission", "", $optionvals, $api_key->get('apk_permission'), '', FALSE);

	echo $formwriter->textinput('Allowed IP addresses (comma separated) (optional)', 'apk_ip_restriction', NULL, 100, $api_key->get('apk_ip_restriction'), '', 255, '');

	echo $formwriter->datetimeinput('Key start time (optional)', 'apk_start_time', 'ctrlHolder', LibraryFunctions::convert_time($api_key->get('apk_start_time'), 'UTC', $session->get_timezone(), 'Y-m-d h:ia'), '', '', '');

	echo $formwriter->datetimeinput('Key expires time (optional)', 'apk_expires_time', 'ctrlHolder', LibraryFunctions::convert_time($api_key->get('apk_expires_time'), 'UTC', $session->get_timezone(), 'Y-m-d h:ia'), '', '', '');

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

?>
