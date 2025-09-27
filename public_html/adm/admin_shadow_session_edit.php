<?php
	
	PathHelper::requireOnce('/includes/AdminPage.php');
	
	PathHelper::requireOnce('/includes/LibraryFunctions.php');

	PathHelper::requireOnce('/data/events_class.php');
	PathHelper::requireOnce('/data/product_details_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	if (isset($_REQUEST['prd_product_detail_id'])) {
		$product_detail = new ProductDetail($_REQUEST['prd_product_detail_id'], TRUE);
	} else {
		$product_detail = new ProductDetail(NULL);
	}

	if($_POST){

		if($_POST['action'] != 'edit'){
			$product_detail = new ProductDetail(NULL);
		}

		$editable_fields = array('prd_num_used', 'prd_notes');

		foreach($editable_fields as $field) {
			$product_detail->set($field, $_POST[$field]);
		}

		$product_detail->prepare();
		$product_detail->save();

		LibraryFunctions::redirect('/admin/admin_shadow_sessions');
		return;
	}

	$page = new AdminPage();
	$page->admin_header(31);
	
	$user = new User($product_detail->get('prd_usr_user_id'), TRUE);

	echo '<h2>Edit sessions for '.$user->display_name() .'</h2>';

	// Editing an existing event
	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	echo $formwriter->begin_form('form', 'POST', '/admin/admin_shadow_session_edit');
	echo '<fieldset>';
	echo '<div class="fields full">';
	
	if($product_detail->key){
		echo $formwriter->hiddeninput('prd_product_detail_id', $product_detail->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}

	echo $formwriter->textinput('Sessions used', 'prd_num_used', NULL, 100, $product_detail->get('prd_num_used'), '', 255, '');
	echo $formwriter->textbox('Notes (dates when used, etc)', 'prd_notes', 'ctrlHolder', 5, 80, $product_detail->get('prd_notes'), '', 'no');

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo '</div></fieldset>';
	echo $formwriter->end_form();

	$page->admin_footer();

?>
