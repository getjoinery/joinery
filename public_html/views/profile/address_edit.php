<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getThemeFilePath('address_edit_logic.php', 'logic'));

	$page_vars = process_logic(address_edit_logic($_GET, $_POST));
	extract($page_vars);

	$page = new PublicPage();
		$hoptions=array(
			'title'=>'Edit Address',
			'breadcrumbs' => array(
				'My Profile' => '/profile/profile',
				'Edit Address' => '',
			),
			);
	$page->public_header($hoptions);
	echo PublicPage::BeginPage('Edit Address', $hoptions);

	echo PublicPage::tab_menu($tab_menus, 'Edit Address');

	$settings = Globalvars::get_instance();
	$formwriter = $page->getFormWriter('form1', 'v2', [
		'model' => $address,
		'edit_primary_key_value' => $address->key
	]);

	$formwriter->begin_form();

	foreach($display_messages AS $display_message) {
		if($display_message->identifier == 'addressbox') {
			echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
		}
	}

	// Get country code options
	$country_codes = Address::get_country_drop_array2();
	$formwriter->dropinput('usa_cco_country_code_id', 'Country', [
		'options' => $country_codes
	]);

	$formwriter->textinput('usa_address1', 'Street Address', [
		'maxlength' => 255,
		'validation' => ['required' => true]
	]);

	$formwriter->textinput('usa_address2', 'Apt, Suite, etc. (optional)', [
		'maxlength' => 255
	]);

	$formwriter->textinput('usa_city', 'City', [
		'maxlength' => 255,
		'validation' => ['required' => true]
	]);

	$formwriter->textinput('usa_state', 'State/Province', [
		'maxlength' => 255,
		'validation' => ['required' => true]
	]);

	$formwriter->textinput('usa_zip_code_id', 'Zip/Postcode', [
		'maxlength' => 255,
		'validation' => ['required' => true]
	]);

	echo '<a href="/profile/account_edit">Cancel</a> ';
	$formwriter->submitbutton('btn_submit', 'Submit');

	$formwriter->end_form();

	$page->endtable();

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
