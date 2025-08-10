<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/PathHelper.php');
PathHelper::requireOnce('includes/ThemeHelper.php');
	ThemeHelper::includeThemeFile('includes/PublicPage.php');
	require_once(LibraryFunctions::get_logic_file_path('contact_preferences_logic.php'));	

	$page_vars = contact_preferences_logic($_GET, $_POST);
	$messages = $page_vars['messages'];

	$tab_menus = array(
		'My Profile' => '/profile',
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
		'Change Subscription' => '/profile/subscription_edit',
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

	
	$formwriter = LibraryFunctions::get_formwriter_object();
	echo $formwriter->begin_form("", "post", "/profile/contact_preferences");
	
	if(empty($page_vars['optionvals'])){
		echo '<p>You are currently not subscribed to any newsletters.</p>';
	}
	else{

		echo $formwriter->checkboxList("Check the box to subscribe:", 'new_list_subscribes', "ctrlHolder", $page_vars['optionvals'], $page_vars['checkedvals'], $page_vars['disabledvals'], $page_vars['readonlyvals']);	
		

		echo $formwriter->hiddeninput('zone', 'optional');
		echo $formwriter->new_form_button('Submit', 'th-btn');
	}
	echo $formwriter->end_form();
	

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array());
?>
