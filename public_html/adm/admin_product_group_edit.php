<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_product_group_edit_logic.php'));

$page_vars = process_logic(admin_product_group_edit_logic($_GET, $_POST));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'product-groups',
	'page_title' => 'Product Groups',
	'readable_title' => 'Product Groups',
	'breadcrumbs' => array(
		'Products'=>'/admin/admin_products',
		'Product Groups' => '',
	),
	'session' => $session,
)
);

$page->begin_box($pageoptions);

// FormWriter V2 with model and edit_primary_key_value
$formwriter = $page->getFormWriter('form1', 'v2', [
	'model' => $product_group,
	'edit_primary_key_value' => $product_group->key
]);

$formwriter->begin_form();

$formwriter->textinput('prg_name', 'Product Group Name', [
	'validation' => ['required' => true, 'maxlength' => 255]
]);

$formwriter->textinput('prg_max_items', 'Max Number of items in this product group that can be added to cart:', [
	'validation' => ['required' => true]
]);

$formwriter->textbox('prg_error', 'Error message if they add too many items:', [
	'rows' => 5,
	'cols' => 80
]);

$formwriter->textbox('prg_description', 'Product Group Description', [
	'rows' => 10,
	'cols' => 80
]);

$formwriter->submitbutton('btn_submit', 'Submit');
$formwriter->end_form();

$page->end_box();

$page->admin_footer();

?>
