<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(8);

	if (isset($_REQUEST['ord_order_id'])) {
		$order = new Order($_REQUEST['ord_order_id'], TRUE);
	} else {
		$order = new Order(NULL);
	}
	
	
	if($_POST){

		$order->set('ord_usr_user_id', $_POST['ord_usr_user_id']);
		
		
		$order->prepare();
		$order->save();
		
		LibraryFunctions::redirect('/admin/admin_order?ord_order_id='.$order->key);
		exit;
	}

	$breadcrumbs = array('Orders'=>'/admin/admin_orders');
	$breadcrumbs += array('Order Edit'=>'');


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 2,
		'page_title' => 'Edit Order',
		'readable_title' => 'Edit Order',
		'breadcrumbs' => $breadcrumbs,
		'session' => $session,
	)
	);
	
	$pageoptions['title'] = "Edit Order";
	$page->begin_box($pageoptions);
	


	// Editing an existing order
	$formwriter = new FormWriterMaster('form1');	
	
	
	echo $formwriter->begin_form('form1', 'POST', '/admin/admin_order_edit');

	if($order->key){
		echo $formwriter->hiddeninput('ord_order_id', $order->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}


	
	if($order->get('ord_usr_user_id')){
		$order_user = new User($order->get('ord_usr_user_id'), TRUE);
	}

	$users = new MultiUser(array('deleted' => FALSE), array('last_name' => ASC));
	$users->load();
	$optionvals = $users->get_dropdown_array();
	
	echo $formwriter->dropinput("User", "ord_usr_user_id", "ctrlHolder", $optionvals, $order_user->key, '', TRUE, FALSE, '/ajax/user_search_ajax');	 


 
	

 
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();

	echo $formwriter->end_form();


	$page->end_box();

	$page->admin_footer();

?>
