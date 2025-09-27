<?php

	PathHelper::requireOnce('/includes/AdminPage.php');

	PathHelper::requireOnce('/includes/LibraryFunctions.php');

	PathHelper::requireOnce('/data/orders_class.php');
	PathHelper::requireOnce('/data/order_items_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(10);

if ($_POST){

	$ord_order_id = LibraryFunctions::fetch_variable('ord_order_id', NULL, 1, 'You must provide a order to delete here.');
	$confirm = LibraryFunctions::fetch_variable('confirm', NULL, 1, 'You must confirm the action.');

	if ($confirm) {

		$order = new Order($ord_order_id, TRUE);
		$order->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$order->permanent_delete();

	}

	//NOW REDIRECT
	$session = SessionControl::get_instance();
	$returnurl = $session->get_return();
	header("Location: $returnurl");
	exit();

}
else{
	$ord_order_id = LibraryFunctions::fetch_variable('ord_order_id', NULL, 1, 'You must provide a order to edit.');

	$order = new Order($ord_order_id, TRUE);

	$session = SessionControl::get_instance();
	$session->set_return("/admin/admin_orders");

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'orders-list',
		'breadcrumbs' => array(
			'Orders'=>'/admin/admin_orders',
			'Order '.$order->key => '',
		),
		//'page_title' => 'Event Sessions',
		//'readable_title' => 'Event Sessions',
		'session' => $session,
	)
	);
	$options['title'] = 'Delete Order';
	//$options['altlinks'] = array('Edit Url'=>'/admin/admin_url_edit?url_url_id='.$url->key);
	$page->begin_box($options);

	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	echo $formwriter->begin_form("form", "post", "/admin/admin_order_delete");

	echo '<fieldset><h4>Confirm Delete</h4>';
		echo '<div class="fields full">';
		echo '<p>WARNING:  This will administratively delete this order ('.$order->key . ').  It will NOT refund any charges.</p>';

	echo $formwriter->hiddeninput("confirm", 1);
	echo $formwriter->hiddeninput("ord_order_id", $ord_order_id);

			echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_buttons();

		echo '</div>';
	echo '</fieldset>';
	echo $formwriter->end_form();

	$page->end_box();
	$page->admin_footer();

}
?>
