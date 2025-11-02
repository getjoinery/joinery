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

	// Render all address fields using the model's static helper method
	Address::renderFormFields($formwriter, [
		'required' => true,
		'include_country' => true,
		'include_user_id' => true,
		'user_id' => $user_id,
		'model' => $address
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');

	$formwriter->end_form();

	$page->end_box();
	$page->admin_footer();

?>
