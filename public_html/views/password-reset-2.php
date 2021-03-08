<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
	require_once (LibraryFunctions::get_logic_file_path('password-reset-2_logic.php'));

	$page = new PublicPage(TRUE);
	$hoptions=array(
		'title'=>'Password Reset', 
	);
	$page->public_header($hoptions,NULL);
		echo PublicPage::BeginPage('Password Reset');
			echo '<div class="section padding-top-20">
			<div class="container">';
	if($message){
		echo $message;
		echo PublicPage::EndPage();	
	}
	else{
		$formwriter = new FormWriterPublic("form1", TRUE, TRUE);
		$validation_rules = array();
		$validation_rules['usr_password']['required']['value'] = 'true';
		$validation_rules['usr_password']['minlength']['value'] = 5;
		$validation_rules['usr_password_again']['required']['value'] = 'true';
		$validation_rules['usr_password_again']['required']['message'] = "'You must enter your password twice to confirm'";
		$validation_rules['usr_password_again']['equalTo']['value'] = "'#usr_password'";
		$validation_rules['usr_password_again']['equalTo']['message'] = "'Your password did not match the one you entered above'";
		echo $formwriter->set_validate($validation_rules);		

		echo $formwriter->begin_form("", "post", "/password-reset-2");
		echo $formwriter->passwordinput("New Password", "usr_password", "ctrlHolder", 20, NULL , 'Must be at least 5 characters.',255, "");
		echo $formwriter->passwordinput("Retype New Password", "usr_password_again", "ctrlHolder", 20, "" , "", 255,"");
		echo $formwriter->hiddeninput('act_code',$act_code);
		echo $formwriter->new_form_button('Submit', 'button button-lg button-dark', 'submit1');
		echo $formwriter->end_form();

	
	}

	echo '</div></div>';
	echo PublicPage::EndPage();	
	$page->public_footer(array('track'=>TRUE, 'formvalidate'=>TRUE));
	
?>
