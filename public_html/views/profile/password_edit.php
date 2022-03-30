<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));
	require_once(LibraryFunctions::get_logic_file_path('password_edit_logic.php'));

	if ($has_old_password) {
		$page_title = 'Change Password';
	} else {
		$page_title = 'Set Password';
	}

	$page = new PublicPageTW(TRUE);
	$hoptions=array(
		'title'=>$page_title, 
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			$page_title => '',
		),
	);
	$page->public_header($hoptions);
	echo PublicPageTW::BeginPage($page_title, $hoptions);

	echo PublicPageTW::tab_menu($tab_menus);
	
	if($message){
		echo $message;
	}
	else{
		
		$formwriter = new FormWriterPublicTW("form1");
					
		$validation_rules = array();
		if ($has_old_password) {
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

		if ($has_old_password) {
			echo $formwriter->passwordinput("Old Password", "usr_old_password", NULL, 20, NULL , '',255, "");
		}
		echo $formwriter->passwordinput("New Password", "usr_password", NULL, 20, NULL , 'Must be at least 5 characters.',255, "");
		echo $formwriter->passwordinput("Retype New Password", "usr_password_again", NULL, 20, "" , "", 255,"");
		echo '<a href="/profile/account_edit">Cancel</a> ';
		echo $formwriter->new_form_button('Submit');

		echo $formwriter->end_form();		
	}	

	echo PublicPageTW::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
