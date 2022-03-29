<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php'));
	require_once (LibraryFunctions::get_logic_file_path('password-reset-1_logic.php'));



	$page = new PublicPageTW();
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Password Reset', 
	);	
	$page->public_header($hoptions,NULL);
	echo PublicPageTW::BeginPage('Reset Password - Step 1 of 2');
	echo PublicPageTW::BeginPanel();

	if($message){
		echo PublicPageTW::alert($message_title, $message, $message_type);
	}
	else{
		$formwriter = new FormWriterPublicTW("form1");
		echo $formwriter->begin_form("", "post", "/password-reset-1", true); 
		echo $formwriter->textinput("Enter the Email Address you registered with", "email", NULL, 20, htmlspecialchars($email), '', 64, NULL);
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_form();
	}

	echo PublicPageTW::EndPanel();
	echo PublicPageTW::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));


?>
