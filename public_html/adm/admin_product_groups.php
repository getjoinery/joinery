<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_product_groups_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$page_vars = process_logic(admin_product_groups_logic($_GET, $_POST));

$session = $page_vars['session'];
$product_groups = $page_vars['product_groups'];

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

$headers = array('Name');
$altlinks = array();
if($session->get_permission() > 7){
	$altlinks['New Product Group'] = '/admin/admin_product_group_edit';
}
$box_vars = array(
	'altlinks' => $altlinks,
	'title' => 'Product Groups'
);
$page->tableheader($headers, $box_vars);

foreach($product_groups as $product_group) {
	$rowvalues=array();
	array_push($rowvalues, $product_group->get('prg_name'));

	$page->disprow($rowvalues);
}

$page->endtable();

$page->admin_footer();

?>
