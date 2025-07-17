<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/AdminPage.php');
	
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	PathHelper::requireOnce('data/product_groups_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(7);


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

		
	$product_groups = new MultiProductGroup();
	$product_groups->load();

	$headers = array('Name');
	$altlinks = array();
	if($_SESSION['permission'] > 7){
		$altlinks['New Product Group'] = '/admin/admin_product_group_edit';
	}
	$box_vars =	array(
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
