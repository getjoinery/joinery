<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/settings_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/email_templates_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	

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
			$user_setting->set('stg_value', $_POST[$user_setting->get('stg_name')]);
			$user_setting->set('stg_update_time', 'NOW()'); 
			$user_setting->set('stg_usr_user_id', $session->get_user_id());
			$user_setting->prepare();
			$user_setting->save();
		}				
		
	}
	
	$settings = Globalvars::get_instance();

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 11,
		'page_title' => 'Settings',
		'readable_title' => 'Settings',
		'breadcrumbs' => array(
			'Settings'=>'', 
		),
		'session' => $session,
	)
	);	
	



	$pageoptions['altlinks'] = array('New Setting'=>'/admin/admin_setting_edit');
	$pageoptions['title'] = "Settings";
	$page->begin_box($pageoptions);

	$formwriter = new FormWriterMaster('form1');
	
	$validation_rules = array();
	$validation_rules['stg_value']['required']['value'] = 'true';
	$validation_rules['stg_name']['required']['value'] = 'true';	
	echo $formwriter->set_validate($validation_rules);	



	echo $formwriter->begin_form('form', 'POST', '/admin/admin_settings');
	
	echo '<h3>General Settings</h3>';
	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Registration active", "register_active", "ctrlHolder", $optionvals, $settings->get_setting('register_active'), '', FALSE);	

	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Newsletter active", "newsletter_active", "ctrlHolder", $optionvals, $settings->get_setting('newsletter_active'), '', FALSE);	
	
	echo '<h3>Blog Settings</h3>';
	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Activate blog", "blog_active", "ctrlHolder", $optionvals, $settings->get_setting('blog_active'), '', FALSE);	

	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Show comments", "show_comments", "ctrlHolder", $optionvals, $settings->get_setting('show_comments'), '', FALSE);	

	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Allow comments", "comments_active", "ctrlHolder", $optionvals, $settings->get_setting('comments_active'), '', FALSE);	

	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Allow comments from unregistered users", "comments_unregistered_users", "ctrlHolder", $optionvals, $settings->get_setting('comments_unregistered_users'), '', FALSE);	

	$optionvals = array("Approved"=>'approved', 'Unapproved' => 'unapproved');
	echo $formwriter->dropinput("Default comment status", "default_comment_status", "ctrlHolder", $optionvals, $settings->get_setting('default_comment_status'), '', FALSE);	

	echo $formwriter->textinput("Comment anti spam word (blank for none)", "anti_spam_answer_comments", "ctrlHolder", 20, $settings->get_setting('anti_spam_answer'), "" , 255, "");

	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Use captcha on comments", "use_captcha_comments", "ctrlHolder", $optionvals, $settings->get_setting('use_captcha_comments'), '', FALSE);	

	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Should blog posts be in the /posts/ subdirectory", "blog_subdirectory", "ctrlHolder", $optionvals, $settings->get_setting('blog_subdirectory'), '', FALSE);	
	
 
	echo '<hr>';
 
 	echo '<h3>Spam Settings</h3>';
	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Use form honeypots", "use_honeypot", "ctrlHolder", $optionvals, $settings->get_setting('use_honeypot'), '', FALSE);	

	echo $formwriter->textinput("Anti spam word (blank for none)", "anti_spam_answer", "ctrlHolder", 20, $settings->get_setting('anti_spam_answer'), "" , 255, "");	

	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Use captcha", "use_captcha", "ctrlHolder", $optionvals, $settings->get_setting('use_captcha'), '', FALSE);	
	
	echo '<hr>';

	echo '<h3>Email Settings</h3>';
	$templates = new MultiEmailTemplateStore(
		array('template_type' => EmailTemplateStore::TEMPLATE_TYPE_OUTER),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$templates->load();
	$outer_optionvals = $templates->get_dropdown_array();

	$templates = new MultiEmailTemplateStore(
		array('template_type' => EmailTemplateStore::TEMPLATE_TYPE_INNER),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$templates->load();
	$inner_optionvals = $templates->get_dropdown_array();

	$templates = new MultiEmailTemplateStore(
		array('template_type' => EmailTemplateStore::TEMPLATE_TYPE_FOOTER),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$templates->load();
	$footer_optionvals = $templates->get_dropdown_array();

	echo $formwriter->dropinput("Bulk email outer template", "bulk_outer_template", "ctrlHolder", $outer_optionvals, $settings->get_setting('bulk_outer_template'), '', FALSE);	
	echo $formwriter->dropinput("Bulk email footer", "bulk_footer", "ctrlHolder", $footer_optionvals, $settings->get_setting('bulk_footer'), '', FALSE);	
	echo $formwriter->dropinput("Individual email inner template", "individual_email_inner_template", "ctrlHolder", $inner_optionvals, $settings->get_setting('individual_email_inner_template'), '', FALSE);	
	echo $formwriter->dropinput("Group email footer template", "group_email_footer_template", "ctrlHolder", $footer_optionvals, $settings->get_setting('group_email_footer_template'), '', FALSE);	
	echo $formwriter->dropinput("Group email outer template", "group_email_outer_template", "ctrlHolder", $outer_optionvals, $settings->get_setting('group_email_outer_template'), '', FALSE);
	echo $formwriter->dropinput("Group email inner template", "group_email_inner_template", "ctrlHolder", $inner_optionvals, $settings->get_setting('group_email_inner_template'), '', FALSE);
	echo $formwriter->dropinput("Event email footer template", "event_email_footer_template", "ctrlHolder", $footer_optionvals, $settings->get_setting('event_email_footer_template'), '', FALSE);
	echo $formwriter->dropinput("Event email outer template", "event_email_outer_template", "ctrlHolder", $outer_optionvals, $settings->get_setting('event_email_outer_template'), '', FALSE);
	echo $formwriter->dropinput("Event email inner template", "event_email_inner_template", "ctrlHolder", $inner_optionvals, $settings->get_setting('event_email_inner_template'), '', FALSE);
	
	//$optionvals = array("General"=>'general', 'Emails' => 'emails');
	//echo $formwriter->dropinput("Setting group", "stg_group_name", "ctrlHolder", $optionvals, $setting->get('stg_group_name'), '', FALSE);


	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();
	
	$page->end_box();


	$page->admin_footer();

?>
