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


	$sort = 'plan_order_month';
	$sdirection = 'ASC';
	
	
	$searches = array();
	$page_choice = $get_vars['page'];
	if($page_choice == 'year'){
		$searches['is_yearly_plan'] = TRUE;
	}
	else{
		$searches['is_monthly_plan'] = TRUE;
		
	}
	
	
	$searches['is_active'] = TRUE;
	$searches['deleted'] = FALSE;
	$searches['in_stock'] = true;	

	$products = new MultiProduct(
		$searches,
		array($sort=>$sdirection),
		10,
		0,
		'AND');
	$products->load();
	$page_vars['products'] = $products;
	$numrecords = $products->count_all();		
	$page_vars['numrecords'] = $numrecords;
	
	$page_vars['currency_symbol'] = Product::$currency_symbols[$settings->get_setting('site_currency')]; 
	
	//$page_vars['pager'] = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	
	return $page_vars;
}
?>

