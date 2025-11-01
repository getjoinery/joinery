<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_phone_edit_logic.php'));

	$page_vars = process_logic(admin_phone_edit_logic($_GET, $_POST));
	extract($page_vars);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'users',
		'page_title' => 'Phone Edit',
		'readable_title' => 'Phone Edit',
		'breadcrumbs' => NULL,
		'session' => $session,
	)
	);

	$pageoptions['title'] = $phone_number->key ? 'Edit Phone Number' : 'Add Phone Number';
	$page->begin_box($pageoptions);

	$formwriter = $page->getFormWriter('form1', 'v2', [
		'model' => $phone_number,
		'edit_primary_key_value' => $phone_number->key
	]);

	$formwriter->begin_form();

	// Hidden field to preserve user_id through form submission
	$formwriter->hiddeninput('usr_user_id', '', ['value' => $user_id]);

	// Get country code options
	$country_codes = PhoneNumber::get_country_code_drop_array();
	$formwriter->dropinput('phn_cco_country_code_id', 'Country code', [
		'options' => $country_codes
	]);

	$formwriter->textinput('phn_phone_number', 'Phone Number', [
		'maxlength' => 20,
		'validation' => ['required' => true]
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');

	$formwriter->end_form();

	$page->end_box();
	$page->admin_footer();

?>
