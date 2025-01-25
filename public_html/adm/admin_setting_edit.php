<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/settings_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	if (isset($_REQUEST['stg_setting_id'])) {
		$setting = new Setting($_REQUEST['stg_setting_id'], TRUE);
	} else {
		$setting = new Setting(NULL);
	}

	if($_POST){
		
		$_POST['stg_name'] = trim(strtolower($_POST['stg_name']));
		$_POST['stg_name'] = preg_replace('/\s+/', '_', $_POST['stg_name']);
		
		$editable_fields = array('stg_name', 'stg_value', 'stg_group_name');

		foreach($editable_fields as $field) {
			$setting->set($field, $_POST[$field]);
		}
		
		if(!$setting->key){
			$setting->set('stg_usr_user_id',$session->get_user_id());
		}	
				
		
		$setting->prepare();
		$setting->save();
		$setting->load();
		
		LibraryFunctions::redirect('/admin/admin_settings');
		exit;
	}


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> NULL,
		'breadcrumbs' => array(
			'Settings'=>'/admin/admin_settings', 
			'New Setting' => '',
		),
		'session' => $session,
	)
	);	

	
	$pageoptions['title'] = "New Setting";
	$page->begin_box($pageoptions);

	// Editing an existing email
	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	
	$validation_rules = array();
	$validation_rules['stg_name']['required']['value'] = 'true';	
	echo $formwriter->set_validate($validation_rules);	



	echo $formwriter->begin_form('form', 'POST', '/admin/admin_setting_edit');

	if($setting->key){
		echo $formwriter->hiddeninput('stg_setting_id', $setting->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}
	
	echo $formwriter->textinput('Setting name', 'stg_name', NULL, 100, $setting->get('stg_name'), '', 255, '');	
	echo $formwriter->textinput('Setting value', 'stg_value', NULL, 100, $setting->get('stg_value'), '', 255, '');	
	
	
	$optionvals = array("General"=>'general', 'Emails' => 'emails');
	echo $formwriter->dropinput("Setting group", "stg_group_name", "ctrlHolder", $optionvals, $setting->get('stg_group_name'), '', FALSE);


	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	$page->admin_footer();

?>
