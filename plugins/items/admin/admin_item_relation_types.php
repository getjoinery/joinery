<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/item_relations_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(7);

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'product-groups',
		'page_title' => 'Product Groups',
		'readable_title' => 'Product Groups',
		'breadcrumbs' => array(
			'Items'=>'/admin/admin_items', 
			'Item Relations' => '',
		),
		'session' => $session,
	)
	);

		
	$item_relations = new MultiProductGroup();
	$item_relations->load();

	$headers = array('Product Group Name');
	$altlinks = array();
	if($_SESSION['permission'] > 7){
		$altlinks['New Product Group'] = '/admin/admin_item_relation_edit';
	}
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => 'Product Groups'
	);
	$page->tableheader($headers, $box_vars);

	foreach($item_relations as $item_relation) {
		$rowvalues=array();
		array_push($rowvalues, $item_relation->get('itr_name'));
		$page->disprow($rowvalues);
	}

	$page->endtable();
		

	$page->admin_footer();

?>
