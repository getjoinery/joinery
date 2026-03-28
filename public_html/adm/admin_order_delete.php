<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_order_delete_logic.php'));

$page_vars = process_logic(admin_order_delete_logic($_GET, $_POST));
extract($page_vars);

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

$formwriter = $page->getFormWriter('form1');
echo $formwriter->begin_form();

echo '<fieldset><h4>Confirm Delete</h4>';
	echo '<div class="fields full">';
	echo '<p>WARNING:  This will administratively delete this order ('.$order->key . ').  It will NOT refund any charges.</p>';

$formwriter->hiddeninput('confirm', '', ['value' => 1]);
$formwriter->hiddeninput('ord_order_id', '', ['value' => $ord_order_id]);

$formwriter->submitbutton('btn_submit', 'Submit');

	echo '</div>';
echo '</fieldset>';
echo $formwriter->end_form();

$page->end_box();
$page->admin_footer();
?>
