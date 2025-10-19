<?php

require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_order_edit_logic.php'));

$page_vars = process_logic(admin_order_edit_logic($_GET, $_POST));
extract($page_vars);

require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'orders-list',
	'page_title' => 'Edit Order',
	'readable_title' => 'Edit Order',
	'breadcrumbs' => $breadcrumbs,
	'session' => $session,
)
);

$pageoptions['title'] = "Edit Order";
$page->begin_box($pageoptions);

// Editing an existing order
$formwriter = $page->getFormWriter('form1');

echo $formwriter->begin_form('form1', 'POST', '/admin/admin_order_edit');

if($order->key){
	echo $formwriter->hiddeninput('ord_order_id', $order->key);
	echo $formwriter->hiddeninput('action', 'edit');
}

$optionvals = $users->get_dropdown_array();

echo $formwriter->dropinput("Billing User", "ord_usr_user_id", "ctrlHolder", $optionvals, $order_user ? $order_user->key : NULL, '', TRUE, FALSE, '/ajax/user_search_ajax');

//ALLOW THESE OTHER FIELDS IF IT IS A NEW ORDER OR NOT A STRIPE ORDER
if(!$order->key || !$order->is_stripe_order()){
	echo $formwriter->textinput('Order total', 'ord_total_cost', NULL, 100, $order->get('ord_total_cost'), '', 255, '');

	echo $formwriter->datetimeinput('Order time', 'ord_timestamp', 'ctrlHolder', LibraryFunctions::convert_time($order->get('ord_timestamp'), 'UTC', $session->get_timezone(), 'Y-m-d h:ia'), '', '', '');
}

echo $formwriter->start_buttons();
echo $formwriter->new_form_button('Submit');
echo $formwriter->end_buttons();

echo $formwriter->end_form();

$page->end_box();

$page->admin_footer();

?>
