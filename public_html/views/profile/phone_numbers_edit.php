<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getThemeFilePath('phone_numbers_edit_logic.php', 'logic'));

	$page_vars = process_logic(phone_numbers_edit_logic($_GET, $_POST));
	extract($page_vars);

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

	echo PublicPage::tab_menu($tab_menus, 'Edit Phone Number');

	$settings = Globalvars::get_instance();
	$formwriter = $page->getFormWriter('form1', 'v2', [
		'model' => $phone_number,
		'edit_primary_key_value' => $phone_number->key
	]);

	$formwriter->begin_form();

	foreach($display_messages AS $display_message) {
		if($display_message->identifier == 'phonebox') {
			echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
		}
	}

	// Get country code options
	$country_codes = PhoneNumber::get_country_code_drop_array();
	$formwriter->dropinput('phn_cco_country_code_id', 'Country code', [
		'options' => $country_codes
	]);

	$formwriter->textinput('phn_phone_number', 'Phone Number', [
		'maxlength' => 20,
		'validation' => ['required' => true]
	]);

	echo '<a href="/profile/account_edit">Cancel</a> ';
	$formwriter->submitbutton('btn_submit', 'Submit');

	$formwriter->end_form();

	$page->endtable();

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
