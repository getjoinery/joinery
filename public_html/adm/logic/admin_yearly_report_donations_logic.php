<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_yearly_report_donations_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/products_class.php'));

	$settings = Globalvars::get_instance();
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];

	$numperpage = 60;
	$startdate = LibraryFunctions::fetch_variable('startdate', '2021-01-01', 0, '');
	$enddate = LibraryFunctions::fetch_variable('enddate', '2021-12-31', 0, '');

	//DIRECT QUERY FOR MEMORY EFFICIENCY
	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

	$sql = "SELECT usr_user_id, usr_email, usr_first_name, usr_last_name FROM usr_users WHERE usr_is_disabled=false ORDER BY usr_last_name ASC";
	try {
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);
	} catch(PDOException $e) {
		$dbhelper->handle_query_error($e);
	}

	//BUILD PRODUCT ARRAY
	$products = new MultiProduct();
	$products->load();
	$product_array = array();
	foreach($products as $product) {
		$product_array[$product->key] = $product->get('pro_name');
	}

	$results = array();
	$numrecords = 0;
	while ($user = $q->fetch()) {
		$total_for_user = 0;
		$results[$user->usr_user_id]['name'] = $user->usr_first_name . ' ' . $user->usr_last_name;
		$results[$user->usr_user_id]['email'] = $user->usr_email;
		$results[$user->usr_user_id]['products'] = array();

		$sql = "SELECT odi_price, odi_pro_product_id, odi_refund_amount FROM odi_order_items WHERE odi_usr_user_id = ? AND odi_status = 2 AND odi_price > 0 AND odi_status_change_time >= ? AND odi_status_change_time <= ?";
		try {
			$r = $dblink->prepare($sql);
			$success = $r->execute([$user->usr_user_id, $startdate, $enddate]);
			$r->setFetchMode(PDO::FETCH_OBJ);
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}

		while($order_item = $r->fetch()){
			$item_amount = $order_item->odi_price - $order_item->odi_refund_amount;
			$results[$user->usr_user_id]['products'][$order_item->odi_pro_product_id]['name'] = $product_array[$order_item->odi_pro_product_id];
			$results[$user->usr_user_id]['products'][$order_item->odi_pro_product_id]['amount'] += $item_amount;
			$total_for_user += $item_amount;
		}
		$results[$user->usr_user_id]['total'] = $total_for_user;
		if($total_for_user > 0){
			$numrecords++;
		}
	}

	// Return data for view
	$result = new LogicResult();
	$result->data = array(
		'startdate' => $startdate,
		'enddate' => $enddate,
		'numperpage' => $numperpage,
		'numrecords' => $numrecords,
		'results' => $results,
		'currency_symbol' => $currency_symbol,
	);

	return $result;
}
?>
