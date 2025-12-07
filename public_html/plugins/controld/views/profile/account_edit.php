<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('account_edit_logic.php', 'logic', 'system', null, 'controld'));	
	
	$page_vars = process_logic(account_edit_logic($_GET, $_POST));

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
		'title'=>'Account Edit', 
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			'Account Edit' => '',
		),
	);
	$page->public_header($hoptions); 

	echo PublicPage::BeginPage('Account Edit', $hoptions);
	
/*
	foreach($page_vars['display_messages'] AS $display_message) {
		if($display_message->identifier == 'userbox') {	
			echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
		}
	}		
*/
	echo PublicPage::tab_menu($tab_menus, 'Edit Account');

	$formwriter = $page->getFormWriter('form1', [
		'action' => '/profile/account_edit'
	]);
	$formwriter->begin_form();

	$formwriter->textinput('usr_first_name', 'First Name', [
		'value' => $page_vars['user']->get('usr_first_name'),
		'maxlength' => 255
	]);
	$formwriter->textinput('usr_last_name', 'Last Name', [
		'value' => $page_vars['user']->get('usr_last_name'),
		'maxlength' => 255
	]);

	$nickname_display = $page_vars['settings']->get_setting('nickname_display_as');
	if($nickname_display){
		$formwriter->textinput('usr_nickname', $nickname_display, [
			'value' => $page_vars['user']->get('usr_nickname'),
			'maxlength' => 255
		]);
	}

	$optionvals = Address::get_timezone_drop_array();
	$formwriter->dropinput('usr_timezone', 'Your Time Zone', [
		'options' => $optionvals,
		'value' => $page_vars['user']->get('usr_timezone')
	]);

	$formwriter->submitbutton('submit', 'Submit', ['class' => 'btn btn-primary']);

	$formwriter->end_form();

	echo PublicPage::EndPage();	
	$page->public_footer($foptions=array('track'=>TRUE));
?>
