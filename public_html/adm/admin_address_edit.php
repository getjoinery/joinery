<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_address_edit_logic.php'));

	$page_vars = process_logic(admin_address_edit_logic($_GET, $_POST));
	extract($page_vars);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'users',
		'page_title' => 'Address Edit',
		'readable_title' => 'Address Edit',
		'breadcrumbs' => NULL,
		'session' => $session,
	)
	);

	$pageoptions['title'] = $address->key ? 'Edit Address' : 'Add Address';
	$page->begin_box($pageoptions);

	$formwriter = $page->getFormWriter('form1', 'v2', [
		'model' => $address,
		'edit_primary_key_value' => $address->key
	]);

	$formwriter->begin_form();

	// Hidden field to preserve user_id through form submission
	$formwriter->hiddeninput('usr_user_id', '', ['value' => $user_id]);

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

	$formwriter->submitbutton('btn_submit', 'Submit');

	$formwriter->end_form();

	$page->end_box();
	$page->admin_footer();

?>
