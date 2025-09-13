<?php
	// LibraryFunctions is now guaranteed available - line removed
	// PathHelper is now guaranteed available - line removed
PathHelper::requireOnce('includes/ThemeHelper.php');
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('password-reset-1_logic.php', 'logic'));

	$page_vars = password_reset_1_logic($_GET, $_POST);

	$page = new PublicPage();
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Password Reset', 
	);	
	$page->public_header($hoptions,NULL);
	echo PublicPage::BeginPage('Reset Password - Step 1 of 2');
	echo PublicPage::BeginPanel();

	if($page_vars['message']){
		echo PublicPage::alert($page_vars['message_title'], $page_vars['message'], $page_vars['message_type']);
	}
	else{
		$settings = Globalvars::get_instance();
		$formwriter = $page->getFormWriter('form1');
		echo $formwriter->begin_form("", "post", "/password-reset-1", true); 
		echo $formwriter->textinput("Enter the Email Address you registered with", "email", NULL, 20, htmlspecialchars($email), '', 64, NULL);
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_form();
	}

	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));


?>
