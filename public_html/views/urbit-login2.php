<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));
	require_once (LibraryFunctions::get_logic_file_path('urbit-login2_logic.php'));

	$page = new PublicPageTW(TRUE);
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Urbit Login Step 2', 
	);
	$page->public_header($hoptions,NULL);
	echo PublicPageTW::BeginPage('Urbit Login Step 2');
	echo PublicPageTW::BeginPanel();
		
	if($message){
		echo PublicPageTW::alert($message_title, $message, $message_type);
	}
	else{
		$formwriter = new FormWriterPublicTW("form1", TRUE, TRUE);
		$validation_rules = array();
		$validation_rules['usr_password']['required']['value'] = 'true';
		$validation_rules['usr_password']['minlength']['value'] = 5;
		echo $formwriter->set_validate($validation_rules);		

		echo $formwriter->begin_form("", "post", "/urbit_login_process", true);
		echo '<p>We sent a login code as a private message to your Urbit.  Check your messages and type in the code here.</p>';
		echo $formwriter->passwordinput("Enter your login code", "urbit_token", NULL, 20, NULL , 'Must be at least 5 characters.',255, "");

		echo $formwriter->checkboxinput("Remember me", "setcookie", NULL, "normal", 'yes', "yes", ''); 

	  
		echo $formwriter->hiddeninput('urbit_ship',$target);
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_form();

	
	}

	echo PublicPageTW::EndPanel();
	echo PublicPageTW::EndPage();	
	$page->public_footer(array('track'=>TRUE, 'formvalidate'=>TRUE));
	
?>

