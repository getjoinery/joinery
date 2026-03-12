<?php
	
	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('password_set_logic.php', 'logic'));

	$page_vars = process_logic(password_set_logic($_GET, $_POST));

	$page = new PublicPage();
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Password Set', 
	);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('Set a Password');
	echo PublicPage::BeginPanel();
	if($message){
		echo PublicPage::alert($page_vars['message_title'], $page_vars['message'], $page_vars['message_type']);
	}
	else{
		$settings = Globalvars::get_instance();
		$formwriter = $page->getFormWriter('form1', ['action' => '/password-set', 'method' => 'post']);

		echo $formwriter->begin_form();

		echo $formwriter->passwordinput('usr_password', 'New Password', ['maxlength' => 255, 'placeholder' => 'Must be at least 5 characters.']);
		echo $formwriter->passwordinput('usr_password_again', 'Retype New Password', ['maxlength' => 255]);

		echo $formwriter->submitbutton('btn_submit', 'Submit', ['class' => 'btn btn-primary']);

		echo $formwriter->end_form();
	}
	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE, 'formvalidate'=>TRUE));
	
?>
