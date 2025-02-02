<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes'));
	require_once(LibraryFunctions::get_logic_file_path('password_edit_logic.php'));

	$page_vars = password_edit_logic($_GET, $_POST);

	$page = new PublicPage();
	$hoptions=array(
		'title'=>$page_vars['page_title'], 
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			$page_vars['page_title'] => '',
		),
	);
	$page->public_header($hoptions);
	echo PublicPage::BeginPage($page_vars['page_title'], $hoptions);

	echo PublicPage::tab_menu($page_vars['tab_menus']);
	
	
	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'tailwind');
				
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
	echo '<a href="/profile/account_edit">Cancel</a> ';
	echo $formwriter->new_form_button('Submit');

	echo $formwriter->end_form();		
	

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
