<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('password_edit_logic.php', 'logic', 'system', null, 'controld'));

	$page_vars = password_edit_logic($_GET, $_POST);

	// Handle LogicResult return format
	if ($page_vars instanceof LogicResult) {
		if ($page_vars->redirect) {
			LibraryFunctions::redirect($page_vars->redirect);
			exit();
		}
		$page_vars = $page_vars->data;
	}

	$tab_menus = array(
		'My Profile' => '/profile',
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
		'Change Tier' => '/profile/change-tier',
	);

	$page = new PublicPage(TRUE);
	$hoptions=array(
		'title'=>$page_vars['page_title'], 
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			$page_vars['page_title'] => '',
		),
	);
	$page->public_header($hoptions);
	echo PublicPage::BeginPage($page_vars['page_title'], $hoptions);

	echo PublicPage::tab_menu($tab_menus, 'Change Password');

	$formwriter = $page->getFormWriter('form1', [
		'action' => '/profile/password_edit'
	]);

	// Note: FormWriter v2 handles validation differently - validation rules applied per-field
	// The set_validate() method is not available in v2

	$formwriter->begin_form();

	foreach($page_vars['display_messages'] AS $display_message) {
		if($display_message->identifier == 'addressbox') {	
			echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
		}
	}

	if ($page_vars['has_old_password']) {
		$formwriter->passwordinput('usr_old_password', 'Old Password', ['maxlength' => 255]);
	}
	$formwriter->passwordinput('usr_password', 'New Password', [
		'maxlength' => 255,
		'hint' => 'Must be at least 5 characters.'
	]);
	$formwriter->passwordinput('usr_password_again', 'Retype New Password', [
		'maxlength' => 255
	]);

	$formwriter->submitbutton('submit', 'Submit', ['class' => 'btn btn-primary']);

	$formwriter->end_form();		

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
