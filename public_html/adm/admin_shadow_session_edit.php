<?php
	
	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));
	
	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('/data/events_class.php'));
	require_once(PathHelper::getIncludePath('/data/product_details_class.php'));

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
	$formwriter = $page->getFormWriter('form1', 'v2', [
		'model' => $product_detail,
		'edit_primary_key_value' => $product_detail->key
	]);
	$formwriter->begin_form();
	
	if($product_detail->key){
		$formwriter->hiddeninput('action', ['value' => 'edit']);
	}

	$formwriter->textinput('prd_num_used', 'Sessions used');
	$formwriter->textbox('prd_notes', 'Notes (dates when used, etc)', [
		'htmlmode' => 'no'
	]);

	$formwriter->submitbutton('submit_button', 'Submit');
	$formwriter->end_form();

	$page->admin_footer();

?>
