<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('password_edit_logic.php', 'logic'));

	$page_vars = process_logic(password_edit_logic(array_merge($_GET, $_POST, $params ?? [])));

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

	echo $page->render_messages('addressbox');

	if ($page_vars['has_old_password']) {
		$formwriter->passwordinput('usr_old_password', 'Old Password', ['maxlength' => 255]);
	}
	$formwriter->passwordinput('usr_password', 'New Password', [
		'maxlength' => 255,
		'helptext' => 'Must be at least 5 characters.'
	]);
	$formwriter->passwordinput('usr_password_again', 'Retype New Password', [
		'maxlength' => 255
	]);

	$formwriter->submitbutton('btn_submit', 'Submit', ['class' => 'btn btn-primary']);

	$formwriter->end_form();		

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
