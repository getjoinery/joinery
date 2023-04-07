<?php
function products_logic($get_vars, $post_vars){
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Pager.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/users_class.php');

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;


	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	$show_events = $settings->get_setting('products_list_events_active');
	$show_items = $settings->get_setting('products_list_items_active');
	if(!$show_events && !$show_items){
		//TURNED OFF
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();			
	}

	$numperpage = 12;
	$page_vars['numperpage'] = $numperpage;
	$offset = $get_vars['offset'];
	$page_vars['offset'] = $offset;
	if(!$offset){
		$offsetdisp = 1;
	}
	else{
		$offsetdisp = $offset + 1;
	}
	$page_vars['offsetdisp'] = $offsetdisp;
	$sort = 'product_id';
	$sdirection = 'ASC';
	$searchterm = $get_vars['searchterm'];
	
	$searches = array();
	$searches['active'] = TRUE;
	
	if($show_items && !$show_events){
		$searches['product_type'] = 2;
	}
	else if($show_events && !$show_items){
		$searches['product_type'] = 1;
	}
	else{ 
		//RETURN ALL
	}

	$searches['in_stock'] = true;
	$sdirection = 'DESC';	

	$products = new MultiProduct(
		$searches,
		array($sort=>$sdirection),
		$numperpage,
		$offset,
		'AND');
	$products->load();
	$page_vars['products'] = $products;
	$numrecords = $products->count_all();		
	$page_vars['numrecords'] = $numrecords;
	
	$page_vars['currency_symbol'] = Product::$currency_symbols[$settings->get_setting('site_currency')]; 
	
	$page_vars['pager'] = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	
	return $page_vars;
}
?>

