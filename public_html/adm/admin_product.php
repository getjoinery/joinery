<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/order_items_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/product_groups_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/product_requirement_instances_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();
	
	$settings = Globalvars::get_instance(); 
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];

	$product = new Product($_GET['pro_product_id'], TRUE);
	$orders = new MultiOrderItem(array('product_id' => $product->key));

		
	if($_REQUEST['action'] == 'remove'){
		if($orders->count_all()){
			throw new SystemDisplayableError('You cannot delete a product with orders.');
		}
		$product->authenticate_write($session);
		$product->permanent_delete();

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_products");
		exit();		
	}	

	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'products-list',
		'breadcrumbs' => array(
			'Products'=>'/admin/admin_products', 
			$product->get('pro_name')=>'',
		),
		'session' => $session,
	)
	);	

		$options['title'] = 'Product: '.$product->get('pro_name');
		$options['altlinks'] = array();
		if($_SESSION['permission'] > 7){
			$options['altlinks'] += array('Edit Product'=> '/admin/admin_product_edit?p='.$product->key);
		}
		
		if(!$orders->count_all()){
			if($_SESSION['permission'] == 10){
				$options['altlinks'] += array('Delete Product'=> '/admin/admin_product?action=remove&pro_product_id='.$product->key);
			}		
		}
		$page->begin_box($options);
		
			
		
		echo '<p>Product Link - <a href="'.$product->get_url() . '">' . $settings->get_setting('webDir_SSL').$product->get_url() . '</a><br />';

		if($product->get('pro_price_type') == Product::PRICE_TYPE_ONE){
			echo 'Price: <b>'.$currency_symbol. $product->get('pro_price').'</b><br>';
		}
		else if($product->get('pro_price_type') == Product::PRICE_TYPE_USER_CHOOSE){
			echo 'Price: <b>User chooses</b><br>';
		}
		
		if($product->get('pro_max_purchase_count') > 0){
			$remaining = $product->get('pro_max_purchase_count') - $product->get_number_purchased();
			echo 'Total items available: <b>'. $product->get('pro_max_purchase_count').' ('. $remaining .' remaining)</b><br>';
		}
		
		echo 'Max that can be added to cart: <b>'. $product->get('pro_max_cart_count').'</b><br>';
		if($product->get('pro_expires')){
			echo 'Purchases expire after: <b>'. $product->get('pro_expires').' days</b><br>';
		}
		else{
			echo 'Purchases expire after: <b>Unlimited days</b><br>';
		}
		if($product->get('pro_evt_event_id')){
			$event = new Event($product->get('pro_evt_event_id'), TRUE);
			$event_date = '';
			if($event->get('evt_start_time')){
				$event_date = '('.LibraryFunctions::convert_time($event->get('evt_start_time'), "UTC", "UTC", 'M j, Y'). ')';
			}
			echo 'Registration for: <b>'.$event_date.'<a href="/admin/admin_event?evt_event_id='.$event->key.'">'.$event->get('evt_name').'</a>'.'</b><br>';
		}
		
		if($product->get('pro_prg_product_group_id')){
			$pg = new ProductGroup($product->get('pro_prg_product_group_id'), TRUE);
			echo 'Product group: <b>'. $pg->get('prg_name').'</b><br>';
		}
	
		if($product->get('pro_digital_link')){
			echo 'Digital link: <b>'.$product->get('pro_digital_link').'</b><br>';
		}

		$requirements = implode(', ', $product->get_requirement_info());
		echo 'Product info collected at purchase: <b>'. $requirements.'</b><br>';
	
		$instances = $product->get_requirement_instances();
		echo 'Additional product info collected at purchase: ';
		
		echo 'Product Description: <b>'. $product->get('pro_description').'</b><br>';

		foreach($instances as $instance){
			$requirement = new ProductRequirement($instance->get('pri_prq_product_requirement_id'), TRUE);
			echo '<b>'.$requirement->get('prq_title').'</b>, ';
		}
		echo '<br>';
		
		//echo 'After purchase message: <b>'. $product->get('pro_after_purchase_message').'</b><br>';

		if($product->get('pro_price_type') == Product::PRICE_TYPE_MULTIPLE){
			echo '<h3>Prices</h3>';
			$versions = $product->get_product_versions();
			if(!count($versions)){
				echo 'None';
			}
			echo '<ul>';
			foreach ($versions as $version) {

				if ($version->prv_status == ProductVersion::ACTIVE) {
					echo '<li>' . $version->prv_version_name . ' - '.$currency_symbol . $version->prv_version_price . 
						' <a href="/admin/admin_product_edit?p=' . $product->key . '&v=' . $version->prv_product_version_id .
						'&action=remove_version">[Make Inactive]</a>' .
						'</li>';
				} else {
					echo '<li style="text-decoration: line-through;">' . $version->prv_version_name . ' - '.$currency_symbol . $version->prv_version_price . 
						' <a href="/admin/admin_product_edit?p=' . $product->key . '&v=' . $version->prv_product_version_id .
						'&action=activate_version">[Make Active]</a>' .
						'</li>';
				}
			}
			echo '</ul>';
			echo '<h4>Add New Price</h4>';
			$formwriter = new FormWriterMaster('form1');
			
			$validation_rules = array();
			$validation_rules['version_name']['required']['value'] = 'true';
			$validation_rules['version_price']['required']['value'] = 'true';
			echo $formwriter->set_validate($validation_rules);				
			
			echo $formwriter->begin_form('form1', 'POST', '/admin/admin_product_edit');
			echo $formwriter->hiddeninput('p', $product->key);
			echo $formwriter->hiddeninput('action', 'new_version');
			echo $formwriter->textinput('Label', 'version_name', NULL, 100, '', '', 255, '');
			echo $formwriter->textinput('Price ('.$currency_symbol.')', 'version_price', 'ctrlHolder', 100, '', '', 255, '');
			echo $formwriter->start_buttons();
			echo $formwriter->new_form_button('Submit');
			echo $formwriter->end_buttons();
			echo $formwriter->end_form();
		}
		$page->end_box();	
	
	$page->admin_footer();
?>


