<?php
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('data/settings_class.php');
	PathHelper::requireOnce('data/email_templates_class.php');
	PathHelper::requireOnce('data/mailing_lists_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$settings = Globalvars::get_instance();

	if($_POST){
		

		$search_criteria = array();
		//$search_criteria['setting_like'] = $searchterm;
		$user_settings = new MultiSetting(
			$search_criteria,
			NULL,
			NULL,
			NULL,
			NULL);
		$user_settings->load();		 

		foreach($user_settings as $user_setting) {
			if(isset($_POST[$user_setting->get('stg_name')])){
				$user_setting->set('stg_value', $_POST[$user_setting->get('stg_name')]);
				$user_setting->set('stg_update_time', 'NOW()'); 
				$user_setting->set('stg_usr_user_id', $session->get_user_id());
				$user_setting->prepare();
				$user_setting->save();
			}
		}				
		LibraryFunctions::redirect('/admin/admin_settings');
	}
	
	

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> NULL,
		'page_title' => 'Controld Settings',
		'readable_title' => 'Controld Settings',
		'breadcrumbs' => array(
			'Settings'=>'', 
		),
		'session' => $session,
	)
	);	
	

	$pageoptions['altlinks'] = array();

	$pageoptions['title'] = "Controld Settings";
	$page->begin_box($pageoptions);

	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	
	$validation_rules = array();
	$validation_rules['stg_value']['required']['value'] = 'true';
	$validation_rules['stg_name']['required']['value'] = 'true';	
	echo $formwriter->set_validate($validation_rules);	

	echo $formwriter->begin_form('form', 'POST', '/plugins/controld/admin/admin_settings_controld');
	

 	echo '<h3>Controld Settings</h3>';

	echo $formwriter->textinput("Controld key", 'controld_key', '', 20, $settings->get_setting('controld_key'), "" , 255, "");

	

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();
	

	
	$page->end_box();

	$page->admin_footer();

?>
