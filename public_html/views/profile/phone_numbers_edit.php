<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getThemeFilePath('phone_numbers_edit_logic.php', 'logic'));

	$page_vars = process_logic(phone_numbers_edit_logic($_GET, $_POST));
	
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

	echo PublicPage::tab_menu($page_vars['tab_menus'], 'Edit Phone Number');

	$settings = Globalvars::get_instance();
	$formwriter = $page->getFormWriter('form1', 'v2');

	// Validation is now handled in PhoneNumber::PlainForm()
	// Additional validation for privacy_policy and evr_first_event if needed

	$formwriter->begin_form();

	foreach($page_vars['display_messages'] AS $display_message) {
		if($display_message->identifier == 'phonebox') {
			echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
		}
	}

	PhoneNumber::PlainForm($formwriter, $page_vars['phone_number']);

	echo '<a href="/profile/account_edit">Cancel</a> ';
	$formwriter->submitbutton('btn_submit', 'Submit');

	$formwriter->end_form();

	$page->endtable();
	
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
