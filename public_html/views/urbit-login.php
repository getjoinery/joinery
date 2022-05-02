<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));



	$page = new PublicPageTW();
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Login with Urbit', 
	);	
	$page->public_header($hoptions,NULL);
	echo PublicPageTW::BeginPage('Login with Urbit');
	echo PublicPageTW::BeginPanel();

	if($message){
		echo PublicPageTW::alert($message_title, $message, $message_type);
	}
	else{
		$formwriter = new FormWriterPublicTW("form1");
		echo $formwriter->begin_form("", "post", "/urbit-login2", true); 
		echo $formwriter->textinput("Your Urbit ship name", "urbit_ship", NULL, 20, '', '', 64, NULL);
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_form();
	}

	echo PublicPageTW::EndPanel();
	echo PublicPageTW::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));


?>

