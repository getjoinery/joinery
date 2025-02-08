<?php
function pricing_logic($get_vars, $post_vars){
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Pager.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/products_class.php');

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;


	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	$pricing_active = $settings->get_setting('pricing_page');
	if(!$pricing_active){
		//TURNED OFF
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();			
	}



	
	
	$searches = array();
	$page_choice = $get_vars['page'];
	

	if($page_choice == 'year'){
		$sort = 'plan_order_year';
		$sdirection = 'ASC';
		$page_vars['page_choice'] = 'year';
		$searches['is_yearly_plan'] = TRUE;

		$searches['is_active'] = TRUE;
		$searches['deleted'] = FALSE;

		$product_versions = new MultiProductVersion(
			$searches,
			array($sort=>$sdirection),
			10,
			0,
			'AND');
		$product_versions->load();


	}
	else{
		$sort = 'plan_order_month';
		$sdirection = 'ASC';
		$page_vars['page_choice'] = 'month';
		$searches['is_monthly_plan'] = TRUE;

		$searches['is_active'] = TRUE;
		$searches['deleted'] = FALSE;

		$product_versions = new MultiProductVersion(
			$searches,
			array($sort=>$sdirection),
			10,
			0,
			'AND');
		$product_versions->load();
		
	}

	$page_vars['product_versions'] = $product_versions;	
	
	$count = 0;
	foreach($product_versions as $product_version){
		$product = new Product($product_version->get('prv_pro_product_id'), TRUE);
		
		if($product->get('pro_is_active') && !$product->get('pro_delete_time')){
			
			$products = new MultiProduct();
			$products->add($product);
			$count++;
		}
	}

	$page_vars['products'] = $products;	
	$page_vars['numrecords'] = $count;


	
	$page_vars['currency_symbol'] = Product::$currency_symbols[$settings->get_setting('site_currency')]; 
	
	//$page_vars['pager'] = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	
	return $page_vars;
}
?>

