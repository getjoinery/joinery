<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
	require_once (LibraryFunctions::get_logic_file_path('password-reset-2_logic.php'));

	$page = new PublicPage(TRUE);
	$hoptions=array(
		'title'=>'Password Reset', 
		'disptitle'=>'Password Reset Step 2 of 2',
		'crumbs'=>array('Home'=>'/', 'Password Reset'=>''),		
		'showmap'=>FALSE,
		'showheader'=>TRUE, 
		'sectionstyle'=>'neutral');
	$page->public_header($hoptions,NULL);

	if($message){
		echo PublicPage::BeginPage('Password Reset');
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

		echo $formwriter->begin_form("uniForm", "post", "/password-reset-2");
		echo '<fieldset class="inlineLabels">';
		echo $formwriter->passwordinput("New Password", "usr_password", "ctrlHolder", 20, NULL , 'Must be at least 5 characters.',255, "");
		echo $formwriter->passwordinput("Retype New Password", "usr_password_again", "ctrlHolder", 20, "" , "", 255,"");
		echo $formwriter->hiddeninput('act_code',$act_code);
		echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Submit', '', 'submit1');
		echo $formwriter->end_buttons(); 	
		echo '</fieldset>';
		echo $formwriter->end_form();

		echo PublicPage::EndPage();		
	}

	$page->public_footer(array('track'=>TRUE, 'formvalidate'=>TRUE));
	
?>
