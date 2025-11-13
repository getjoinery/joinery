<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('password_edit_logic.php', 'logic'));

	$page_vars = process_logic(password_edit_logic($_GET, $_POST));

	$page = new PublicPage();
	$hoptions=array(
		'title'=>$page_vars['page_title'], 
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			$page_vars['page_title'] => '',
		),
	);
	$page->public_header($hoptions);
	
	echo PublicPage::BeginPage($page_vars['page_title'], $hoptions);

	echo PublicPage::tab_menu($page_vars['tab_menus'], 'Change Password');

	$settings = Globalvars::get_instance();
	$formwriter = $page->getFormWriter('form1', [
		'action' => '/profile/password_edit'
	]);

	$formwriter->begin_form();

	foreach($page_vars['display_messages'] AS $display_message) {
		if($display_message->identifier == 'addressbox') {
			echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
		}
	}

	if ($page_vars['has_old_password']) {
		$formwriter->passwordinput('usr_old_password', 'Old Password');
	}
	$formwriter->passwordinput('usr_password', 'New Password', [
		'description' => 'Must be at least 5 characters.'
	]);
	$formwriter->passwordinput('usr_password_again', 'Retype New Password');
	echo '<a href="/profile/account_edit">Cancel</a> ';
	$formwriter->submitbutton('btn_submit', 'Submit');

	$formwriter->end_form();		

	echo PublicPage::EndPage();
	
	$page->public_footer($foptions=array('track'=>TRUE));

?>
