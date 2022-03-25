<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Pager.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/users_class.php');

	$session = SessionControl::get_instance();

	$settings = Globalvars::get_instance();
	$show_events = $settings->get_setting('products_list_events_active');
	$show_items = $settings->get_setting('products_list_items_active');
	if(!$show_events && !$show_items){
		//TURNED OFF
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();			
	}

	$numperpage = 5;
	$offset = LibraryFunctions::fetch_variable('offset', NULL, 0, '');
	if(!$offset){
		$offsetdisp = 1;
	}
	else{
		$offsetdisp = $offset + 1;
	}
	$sort = 'product_id';
	$sdirection = 'ASC';
	$searchterm = LibraryFunctions::fetch_variable('searchterm', NULL, 0, '');
	$user_id = LibraryFunctions::fetch_variable('u', NULL, 0, '');
	
	$searches = array();
	$searches['active'] = TRUE;
	
	if($show_items){
		$searches['product_type'] = 2;
	}
	
	if($show_events){
		$searches['product_type'] = 1;
	}

	$sdirection = 'DESC';	

	$products = new MultiProduct(
		$searches,
		array($sort=>$sdirection),
		$numperpage,
		$offset,
		'AND');
	$products->load();	
	$numrecords = $products->count_all();		
	
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')]; 
	
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
  
?>

