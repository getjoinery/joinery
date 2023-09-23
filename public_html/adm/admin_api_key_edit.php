<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/api_keys_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$settings = Globalvars::get_instance(); 

	if (isset($_REQUEST['apk_api_key_id'])) {
		$api_key = new ApiKey($_REQUEST['apk_api_key_id'], TRUE);
	} 
	else {
		$api_key = new ApiKey(NULL);
	}

	
	if($_POST){

		$editable_fields = array('apk_name','apk_is_active','apk_permission', 'apk_ip_restriction');

		
		foreach($editable_fields as $field) {
			$api_key->set($field, $_POST[$field]);
		}

		$api_key->set('apk_usr_user_id', $session->get_user_id());
		$public_key = 'public_'.LibraryFunctions::random_string(16);
		//$secret_key = 'secret_'.LibraryFunctions::random_string(16);
		$secret_key = 'test1';
		$api_key->set('apk_public_key', $public_key);
		$api_key->set('apk_secret_key', ApiKey::GenerateKey($secret_key));
		
		$api_key->prepare();
		$api_key->save();
		$api_key->load();
		
		LibraryFunctions::redirect('/admin/admin_api_key?apk_api_key_id='. $api_key->key);
		exit;
	}

	$title = $api_key->get('apk_name');
	$content = $api_key->get('apk_description');
	
	


	$api_keyt = new AdminPage();
	$api_keyt->admin_header(	
	array(
		'menu-id'=> 'api_keys',
		'breadcrumbs' => array(
			'ApiKeys'=>'/admin/admin_api_keys', 
			'Edit ApiKey' => '',
		),
		'session' => $session,
	)
	);	

	
	$api_keyoptions['title'] = "Edit ApiKey";
	$api_keyt->begin_box($api_keyoptions);

	
	// Editing an existing email
	$formwriter = new FormWriterMaster('form1');
	
	$validation_rules = array();
	$validation_rules['apk_name']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	



	echo $formwriter->begin_form('form', 'POST', '/admin/admin_api_key_edit');

	if($api_key->key){
		echo $formwriter->hiddeninput('apk_api_key_id', $api_key->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}
	
	echo $formwriter->textinput('Key name', 'apk_name', NULL, 100, $api_key->get('apk_name'), '', 255, '');		
	
	//echo $formwriter->textinput('Public street address', 'apk_address', NULL, 100, $api_key->get('apk_address'), '', 255, '');
	//echo $formwriter->textinput('ApiKey website', 'apk_website', NULL, 100, $api_key->get('apk_website'), '', 255, '');

	$optionvals = array("No"=>0, "Yes"=>1);
	echo $formwriter->dropinput("Active", "apk_is_active", "", $optionvals, $api_key->get('apk_is_active'), '', FALSE);
	
	$optionvals = array("Read only"=>1, "Write only"=>2, "Read/Write"=>3, "Read/Write/Delete"=>4);
	echo $formwriter->dropinput("Permission", "apk_permission", "", $optionvals, $api_key->get('apk_permission'), '', FALSE);
	
	
	echo $formwriter->textinput('Allowed IP addresses (comma separated)', 'apk_ip_restriction', NULL, 100, $api_key->get('apk_ip_restriction'), '', 255, '');

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();




	$api_keyt->end_box();
	

	$api_keyt->admin_footer();

?>
