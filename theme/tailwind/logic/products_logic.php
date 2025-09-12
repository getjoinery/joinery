<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

function products_logic($get_vars, $post_vars){
	// SessionControl is now guaranteed available - line removed
	// LibraryFunctions is now guaranteed available - line removed
	PathHelper::requireOnce('includes/Pager.php');
	PathHelper::requireOnce('data/products_class.php');
	PathHelper::requireOnce('data/users_class.php');

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

	if($get_vars['numperpage']){
		$numperpage = $get_vars['numperpage'];
	}
	else{
		$numperpage = 12;
	}
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
	
	if($get_vars['sort']){
		$sort = $get_vars['sort'];
	}
	else{
		$sort = 'product_id';
	}
	
	if($get_vars['sdirection']){
		$sdirection = $get_vars['sdirection'];
	}
	else{
		$sdirection = 'DESC';
	}
	
	$searchterm = $get_vars['searchterm'];
	
	$searches = array();
	$searches['is_active'] = TRUE;
	
	if($get_vars['subscriptions'] == 'all'){
		//NO FILTER
	}

	 
	
	
	$searches['deleted'] = FALSE;
	
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

