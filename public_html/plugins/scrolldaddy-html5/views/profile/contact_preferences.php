<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('contact_preferences_logic.php', 'logic'));

	$page_vars = process_logic(contact_preferences_logic($_GET, $_POST));

	$messages = $page_vars['messages'];

	$tab_menus = array(
		'My Profile' => '/profile',
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
		'Change Tier' => '/profile/change-tier',
	);
	
	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Contact Preferences',
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			'Contact Preferences' => '',
		),
	);
	$page->public_header($hoptions);
	echo PublicPage::BeginPage('Contact Preferences', $hoptions);

	echo PublicPage::tab_menu($tab_menus, 'Change Contact Preferences');

	foreach ($messages as $message){
		echo PublicPage::alert($message['message_title'], $message['message'], $message['message_type']);
	}	

	$formwriter = $page->getFormWriter('form1', [
		'action' => '/profile/contact_preferences'
	]);
	$formwriter->begin_form();

	if(empty($page_vars['optionvals'])){
		echo '<p>You are currently not subscribed to any newsletters.</p>';
	}
	else{

		$formwriter->checkboxList('new_list_subscribes', 'Check the box to subscribe:', [
			'options' => $page_vars['optionvals'],
			'checked' => $page_vars['checkedvals']
		]);

		$formwriter->hiddeninput('zone', '', ['value' => 'optional']);
		$formwriter->submitbutton('btn_submit', 'Submit', ['class' => 'btn btn-primary']);
	}
	$formwriter->end_form();

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array());
?>
