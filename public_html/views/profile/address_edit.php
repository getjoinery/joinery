<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
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
	$formwriter = $page->getFormWriter('form1', [
		'model' => $address,
		'edit_primary_key_value' => $address->key
	]);

	$formwriter->begin_form();

	foreach($display_messages AS $display_message) {
		if($display_message->identifier == 'addressbox') {
			echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
		}
	}

	// Render all address fields using the model's static helper method
	Address::renderFormFields($formwriter, [
		'required' => true,
		'include_country' => true,
		'include_user_id' => false,
		'model' => $address
	]);

	echo '<a href="/profile/account_edit">Cancel</a> ';
	$formwriter->submitbutton('btn_submit', 'Submit');

	$formwriter->end_form();

	$page->endtable();

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
