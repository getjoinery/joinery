<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
	require_once(LibraryFunctions::get_logic_file_path('password_edit_logic.php'));

	if ($has_old_password) {
		$page_title = 'Change Password';
	} else {
		$page_title = 'Set Password';
	}

	$page = new PublicPage(TRUE);
	$page->public_header(array(
		'title' => $page_title
	));
	echo '<a class="back-link" href="/profile/profile">My Profile</a> > '.$page_title.'<br />';
	echo PublicPage::BeginPage($page_title);

	if($message){
		echo $message;
	}
	else{
		
		$formwriter = new FormWriterPublic("form1");
					
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
					
		echo $formwriter->begin_form("uniForm", "post", "/profile/password_edit");
		echo '<fieldset class="inlineLabels">';

		if ($has_old_password) {
			echo $formwriter->passwordinput("Old Password", "usr_old_password", "ctrlHolder", 20, NULL , '',255, "");
		}
		echo $formwriter->passwordinput("New Password", "usr_password", "ctrlHolder", 20, NULL , 'Must be at least 5 characters.',255, "");
		echo $formwriter->passwordinput("Retype New Password", "usr_password_again", "ctrlHolder", 20, "" , "", 255,"");
		echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Submit', '');
		echo $formwriter->end_buttons();
		echo '</fieldset>';

		echo $formwriter->end_form();		
	}		
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
