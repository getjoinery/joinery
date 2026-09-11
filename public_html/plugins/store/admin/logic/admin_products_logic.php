<?php

function admin_products_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$searches = array();
	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '', $input);
	$sort = LibraryFunctions::fetch_variable('sort', 'product_id', 0, '', $input);
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '', $input);
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '', $input);
	$filter = LibraryFunctions::fetch_variable('filter', '', 0, '', $input);

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
		$breadcrumb_array = array('Products'=>'/plugins/store/admin/admin_products', 'Active Products'=>'');
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
