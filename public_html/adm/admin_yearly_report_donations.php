<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');

	$PRODUCT_ID_TO_NAME_CACHE = array();

	$session = SessionControl::get_instance();
	$session->check_permission(10);
	$session->set_return();
	
	$settings = Globalvars::get_instance();
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];

	$numperpage = 60;
	//$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	//$sort = LibraryFunctions::fetch_variable('sort', 'ord_order_id', 0, '');	
	//$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	
	//$user_id = LibraryFunctions::fetch_variable('u', NULL, 0, '');
	$startdate = LibraryFunctions::fetch_variable('startdate', '2021-01-01', 0, '');	
	$enddate = LibraryFunctions::fetch_variable('enddate', '2021-12-31', 0, '');	

	$search_criteria = NULL;
	$search_criteria = array();
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 4,
		'breadcrumbs' => array(
			'Orders'=>'', 
		),
		'page_title' => 'Orders',
		'readable_title' => 'Orders',
		'session' => $session,
	)
	);	

	$formwriter = new FormWriterMaster("form1");
	echo $formwriter->begin_form("", "get", "/admin/admin_yearly_report_donations");
	echo $formwriter->dateinput("Start Date", "startdate", "dateinput", 30, $startdate, "", 10);
	echo $formwriter->dateinput("End Date", "enddate", "dateinput", 30, $enddate, "", 10);
	echo $formwriter->hiddeninput('source', 'form');
	echo '<b>All times in UTC.</b>';
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();	

	
	$headers = array('Name', 'Product', 'Total');
	$altlinks = array();
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Orders',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);
	
	$result_array = array();
	
	//DIRECT QUERY FOR MEMORY EFFICIENCY
	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

	$sql = "SELECT usr_user_id, usr_email, usr_first_name, usr_last_name FROM usr_users WHERE usr_is_disabled=false ORDER BY usr_last_name ASC";
	try {
		$q = $dblink->prepare($sql);
		//$q->bindValue(1, $abbr, PDO::PARAM_STR);
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
	while ($user = $q->fetch()) {
		$total_for_user = 0;
		$results[$user->usr_user_id][name] = $user->usr_first_name . ' ' . $user->usr_last_name;
		$results[$user->usr_user_id][email] = $user->usr_email;
		$results[$user->usr_user_id][products] = array();	
			
		$sql = "SELECT odi_price, odi_pro_product_id, odi_refund_amount FROM odi_order_items WHERE odi_usr_user_id = ".$user->usr_user_id." AND odi_status = 2 AND odi_price > 0 AND odi_status_change_time >= '".$startdate . "' and odi_status_change_time <= '" . $enddate . "'";
		try {
			$r = $dblink->prepare($sql);
			$success = $r->execute();
			$r->setFetchMode(PDO::FETCH_OBJ);
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}			
		
		while($order_item = $r->fetch()){
			$item_amount = $order_item->odi_price - $order_item->odi_refund_amount;
			$results[$user->usr_user_id][products][$order_item->odi_pro_product_id][name] = $product_array[$order_item->odi_pro_product_id];
			$results[$user->usr_user_id][products][$order_item->odi_pro_product_id][amount] += $item_amount;
			$total_for_user += $item_amount;
		}
		$results[$user->usr_user_id][total] = $total_for_user;
	}
	
	foreach($results as $result){
		if($result[total] > 0){
			$rowvalues = array();
			array_push($rowvalues, $result[name] . ' ('.$result[email].')');
			$page->disprow($rowvalues);
			foreach($result[products] as $product){
				$rowvalues = array();
				array_push($rowvalues, '');
				array_push($rowvalues, $product[name]);
				array_push($rowvalues, $currency_symbol.$product[amount]);
				$page->disprow($rowvalues);				
			}
		
			$rowvalues = array();
			array_push($rowvalues, '');
			array_push($rowvalues, '<b>Total:</b>');
			array_push($rowvalues, '<b>'.$currency_symbol.$result[total].'</b>');
			$page->disprow($rowvalues);		
		}		
	}
	
	$page->endtable($pager);		
	
	$page->admin_footer();

?>
