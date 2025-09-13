<?php
	// LibraryFunctions is now guaranteed available - line removed
	// PathHelper is now guaranteed available - line removed
PathHelper::requireOnce('includes/ThemeHelper.php');
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('password-reset-2_logic.php', 'logic'));

	$page_vars = password_reset_2_logic($_GET, $_POST);
	
	$page = new PublicPage();
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Password Reset', 
	);
	$page->public_header($hoptions,NULL);
	echo PublicPage::BeginPage('Password Reset');
	echo PublicPage::BeginPanel();
		
	if($page_vars['message']){
		echo PublicPage::alert($page_vars['message_title'], $page_vars['message'], $page_vars['message_type']);
	}
	else{
		$settings = Globalvars::get_instance();
		$formwriter = $page->getFormWriter('form1');
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
		echo $formwriter->hiddeninput('act_code',$page_vars['act_code']);
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_form();

	
	}

	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();	
	$page->public_footer(array('track'=>TRUE, 'formvalidate'=>TRUE));
	
?>
