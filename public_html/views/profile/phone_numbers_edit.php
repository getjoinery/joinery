<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes'));
	require_once(LibraryFunctions::get_logic_file_path('phone_numbers_edit_logic.php'));

	$page_vars = phone_numbers_edit_logic($_GET, $_POST);
	
	$page = new PublicPage();
		$hoptions=array(
			'title'=>'Edit Phone Number',
			'breadcrumbs' => array(
				'My Profile' => '/profile/profile',
				'Edit Phone Number' => '',
			),
			);
	$page->public_header($hoptions);
	echo PublicPage::BeginPage('Add/Edit Phone Number', $hoptions);


	echo PublicPage::tab_menu($page_vars['tab_menus']);

	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'tailwind');
	
	$validation_rules = array();
	$validation_rules['phn_phone_number']['required']['value'] = 'true';
	$validation_rules['privacy_policy']['required']['value'] = 'true';
	$validation_rules['evr_first_event']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	
	
	echo $formwriter->begin_form("", "post", "/profile/phone_numbers_edit");

	foreach($page_vars['display_messages'] AS $display_message) {
		if($display_message->identifier == 'phonebox') {	
			echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
		}
	}

	PhoneNumber::PlainForm($formwriter, $page_vars['phone_number']);

	echo '<a href="/profile/account_edit">Cancel</a> ';
	echo $formwriter->new_form_button('Submit');

	echo $formwriter->end_form();

	$page->endtable();
	
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
