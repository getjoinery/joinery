<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
	require_once (LibraryFunctions::get_logic_file_path('password-reset-1_logic.php'));



	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Password Reset', 
	);	
	$page->public_header($hoptions,NULL);
	echo PublicPage::BeginPage('Reset Password - Step 1 of 2');
		echo '<div class="section padding-top-20">
			<div class="container">';

	if($message){
		echo $message;
	}
	else{
		$formwriter = new FormWriterPublic("form1");
		echo $formwriter->begin_form("", "post", "/password-reset-1"); 
		echo $formwriter->textinput("Enter the Email Address you registered with", "email", "ctrlHolder", 20, htmlspecialchars($email), '', 64, NULL);
		echo $formwriter->new_form_button('Submit', '', 'submit1');
		echo $formwriter->end_form();
	}

	echo '</div></div>';
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));


?>
