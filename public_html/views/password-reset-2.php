<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));
	require_once (LibraryFunctions::get_logic_file_path('password-reset-2_logic.php'));

	$page_vars = password_reset_2_logic($_GET, $_POST);
	
	$page = new PublicPageTW(TRUE);
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Password Reset', 
	);
	$page->public_header($hoptions,NULL);
	echo PublicPageTW::BeginPage('Password Reset');
	echo PublicPageTW::BeginPanel();
		
	if($page_vars['message']){
		echo PublicPageTW::alert($page_vars['message_title'], $page_vars['message'], $page_vars['message_type']);
	}
	else{
		$formwriter = new FormWriterPublicTW("form1", TRUE, TRUE);
		$validation_rules = array();
		$validation_rules['usr_password']['required']['value'] = 'true';
		$validation_rules['usr_password']['minlength']['value'] = 5;
		$validation_rules['usr_password_again']['required']['value'] = 'true';
		$validation_rules['usr_password_again']['required']['message'] = "'You must enter your password twice to confirm'";
		$validation_rules['usr_password_again']['equalTo']['value'] = "'#usr_password'";
		$validation_rules['usr_password_again']['equalTo']['message'] = "'Your password did not match the one you entered above'";
		echo $formwriter->set_validate($validation_rules);		

		echo $formwriter->begin_form("", "post", "/password-reset-2", true);
		echo $formwriter->passwordinput("New Password", "usr_password", NULL, 20, NULL , 'Must be at least 5 characters.',255, "");
		echo $formwriter->passwordinput("Retype New Password", "usr_password_again", NULL, 20, "" , "", 255,"");
		//echo $formwriter->hiddeninput('act_code',$act_code);
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_form();

	
	}

	echo PublicPageTW::EndPanel();
	echo PublicPageTW::EndPage();	
	$page->public_footer(array('track'=>TRUE, 'formvalidate'=>TRUE));
	
?>
