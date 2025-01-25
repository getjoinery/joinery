<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	require_once (LibraryFunctions::get_logic_file_path('password-set_logic.php'));

	$page_vars = password_set_logic($_GET, $_POST);

	$page = new PublicPageTW(TRUE);
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Password Set', 
	);
	$page->public_header($hoptions,NULL);

	echo PublicPageTW::BeginPage('Set a Password');
	echo PublicPageTW::BeginPanel();
	if($message){
		echo PublicPageTW::alert($page_vars['message_title'], $page_vars['message'], $page_vars['message_type']);
	}
	else{
		$formwriter = LibraryFunctions::get_formwriter_object('form1', 'tailwind');

		$validation_rules = array();
		$validation_rules['usr_password']['required']['value'] = 'true';
		$validation_rules['usr_password']['minlength']['value'] = 5;
		$validation_rules['usr_password_again']['required']['value'] = 'true';
		$validation_rules['usr_password_again']['required']['message'] = "'You must enter your password twice to confirm'";
		$validation_rules['usr_password_again']['equalTo']['value'] = "'#usr_password'";
		$validation_rules['usr_password_again']['equalTo']['message'] = "'Your password did not match the one you entered above'";
		echo $formwriter->set_validate($validation_rules);	

		echo $formwriter->begin_form("form1", "post", "/password-set", true);

		echo $formwriter->passwordinput("New Password", "usr_password", NULL, 20, NULL , 'Must be at least 5 characters.',255, "");
		echo $formwriter->passwordinput("Retype New Password", "usr_password_again", NULL, 20, "" , "", 255,"");

		echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_buttons(); 


		echo $formwriter->end_form();
	}
	echo PublicPageTW::EndPanel();
	echo PublicPageTW::EndPage();
	$page->public_footer(array('track'=>TRUE, 'formvalidate'=>TRUE));
	
?>
