<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/product_groups_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(7);
	
	if (isset($_REQUEST['prg_product_group_id'])) {
		$product_group = new ProductGroup($_REQUEST['prg_product_group_id'], TRUE);
	} else {
		$product_group = new ProductGroup(NULL);
	}

	if ($_POST) {
		// Submitting a product edit

		$editable_fields = array('prg_max_items', 'prg_error', 'prg_name', 'prg_description');

		foreach($editable_fields as $field) {
			$product_group->set($field, $_POST[$field]);
		}

		$product_group->save();

		LibraryFunctions::redirect('/admin/admin_product_groups');	
		exit;
	} 

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 5,
		'page_title' => 'Product Groups',
		'readable_title' => 'Product Groups',
		'breadcrumbs' => array(
			'Products'=>'/admin/admin_products', 
			'Product Groups' => '',
		),
		'session' => $session,
	)
	);
	

	
	$options['title'] = 'Edit Product Group';
	$page->begin_box($options);

	// Editing an existing product
	$formwriter = new FormWriterMaster('form1');
	
	$validation_rules = array();
	$validation_rules['prg_name']['required']['value'] = 'true';
	$validation_rules['prg_max_items']['required']['value'] = 'true';
	$validation_rules['prg_error']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);				
	
	
	echo $formwriter->begin_form('form1', 'POST', '/admin/admin_product_group_edit');
	echo $formwriter->hiddeninput('prg_product_group_id', $product_group->key);
	echo $formwriter->textinput('Product Group Name', 'prg_name', NULL, 100, $product_group->get('prg_name'), '', 255, '');
	echo $formwriter->textinput('Max Number of items in this product group that can be added to cart:', 'prg_max_items', 'ctrlHolder', 100, $product_group->get('prg_max_items'), '', 255, '');

	echo $formwriter->textbox('Error message if they add too many items:', 'prg_error', 'ctrlHolder', 10, 80, $product_group->get('prg_error'), '');
	echo $formwriter->textbox('Product Group Description', 'prg_description', 'ctrlHolder', 10, 80, $product_group->get('prg_description'), '');

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();
	
	$page->end_box();


	$page->admin_footer();

?>
