<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/PathHelper.php');
PathHelper::requireOnce('includes/ThemeHelper.php');
	ThemeHelper::includeThemeFile('includes/PublicPage');
	require_once(LibraryFunctions::get_logic_file_path('password_edit_logic.php'));

	$page_vars = password_edit_logic($_GET, $_POST);

	$tab_menus = array(
		'My Profile' => '/profile',
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
		'Change Subscription' => '/profile/subscription_edit',
	);

	$page = new PublicPage(TRUE);
	$hoptions=array(
		'title'=>$page_vars['page_title'], 
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			$page_vars['page_title'] => '',
		),
	);
	$page->public_header($hoptions);
	echo PublicPage::BeginPage($page_vars['page_title'], $hoptions);

	echo PublicPage::tab_menu($tab_menus, 'Change Password');
	
	
	$formwriter = LibraryFunctions::get_formwriter_object();
				
	$validation_rules = array();
	if ($page_vars['has_old_password']) {
		$validation_rules['usr_old_password']['required']['value'] = 'true';
	}
	$validation_rules['usr_password']['required']['value'] = 'true';
	$validation_rules['usr_password']['minlength']['value'] = 5;
	$validation_rules['usr_password_again']['required']['value'] = 'true';
	$validation_rules['usr_password_again']['required']['message'] = "'You must enter your password twice to confirm'";
	$validation_rules['usr_password_again']['equalTo']['value'] = "'#usr_password'";
	$validation_rules['usr_password_again']['equalTo']['message'] = "'Your password did not match the one you entered above'";
	echo $formwriter->set_validate($validation_rules);					
				
	echo $formwriter->begin_form("", "post", "/profile/password_edit");

	foreach($page_vars['display_messages'] AS $display_message) {
		if($display_message->identifier == 'addressbox') {	
			echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
		}
	}

	if ($page_vars['has_old_password']) {
		echo $formwriter->passwordinput("Old Password", "usr_old_password", NULL, 20, NULL , '',255, "");
	}
	echo $formwriter->passwordinput("New Password", "usr_password", NULL, 20, NULL , 'Must be at least 5 characters.',255, "");
	echo $formwriter->passwordinput("Retype New Password", "usr_password_again", NULL, 20, "" , "", 255,"");

	echo $formwriter->new_form_button('Submit', 'th-btn');

	echo $formwriter->end_form();		
	

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
