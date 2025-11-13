<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_products_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/products_class.php'));
	require_once(PathHelper::getIncludePath('data/order_items_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$searches = array();
	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '', $get_vars);
	$sort = LibraryFunctions::fetch_variable('sort', 'product_id', 0, '', $get_vars);
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '', $get_vars);
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '', $get_vars);
	$filter = LibraryFunctions::fetch_variable('filter', '', 0, '', $get_vars);

	if($searchterm) {
		if(is_numeric($searchterm)) {
			$searches['product_id'] = $searchterm;
		}
		else {
			$searches['name_like'] = $searchterm;
		}
	}

	if($filter == 'all'){
		$breadcrumb_array = array('Products'=>'All Products');
	}
	else{
		$breadcrumb_array = array('Products'=>'/admin/admin_products', 'Active Products'=>'');
		$searches['is_active'] = true;
	}

	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$searches['deleted'] = false;
	}

	$products = new MultiProduct(
		$searches,
		array($sort=>$sdirection),
		$numperpage,
		$offset,
		'AND'
	);
	$numrecords = $products->count_all();
	$products->load();

	$page_vars = array(
		'session' => $session,
		'products' => $products,
		'numrecords' => $numrecords,
		'numperpage' => $numperpage,
		'breadcrumb_array' => $breadcrumb_array
	);

	return LogicResult::render($page_vars);
}
?>
