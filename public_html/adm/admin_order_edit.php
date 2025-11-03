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

// Prepare override values for timezone conversion
$override_values = [];
if($order->key && $order->get('ord_timestamp')){
	$override_values['ord_timestamp'] = LibraryFunctions::convert_time(
		$order->get('ord_timestamp'),
		'UTC',
		$session->get_timezone(),
		'Y-m-d H:i:s'
	);
}

// Editing an existing order
$formwriter = $page->getFormWriter('form1', [
	'model' => $order,
	'edit_primary_key_value' => $order->key,
	'values' => $override_values
]);

$formwriter->begin_form();

$optionvals = $users->get_dropdown_array();
$formwriter->dropinput('ord_usr_user_id', 'Billing User', [
	'options' => $optionvals,
	'ajaxendpoint' => '/ajax/user_search_ajax'
]);

//ALLOW THESE OTHER FIELDS IF IT IS A NEW ORDER OR NOT A STRIPE ORDER
if(!$order->key || !$order->is_stripe_order()){
	$formwriter->textinput('ord_total_cost', 'Order total');
	$formwriter->datetimeinput('ord_timestamp', 'Order time');
}

$formwriter->submitbutton('submit_button', 'Submit');
$formwriter->end_form();

$page->end_box();

$page->admin_footer();

?>
