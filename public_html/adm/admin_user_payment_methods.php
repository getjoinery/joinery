<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/StripeHelper.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

	$settings = Globalvars::get_instance();


	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();
	
	$settings = Globalvars::get_instance();

	$user_id = LibraryFunctions::fetch_variable('usr_user_id', NULL, 1, 'You must pass a user id');
	$user = new User($user_id, TRUE);


	$stripe_helper = new StripeHelper();
	
	if($stripe_helper->test_mode){
		$methods = $stripe_helper->get_payment_methods($user->get('usr_stripe_customer_id_test'));
	}
	else{
		$methods = $stripe_helper->get_payment_methods($user->get('usr_stripe_customer_id'));
	}
	
	
	

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'orders-list',
		'breadcrumbs' => array(
			'Users'=>'/admin/admin_users', 
			'User'=>'/admin/admin_user?usr_user_id='.$user->key, 
			'Payment Methods' => '',
		),
		//'page_title' => 'Event Sessions',
		//'readable_title' => 'Event Sessions',
		'session' => $session,
	)
	);	





	$headers = array('Stripe ID','Payment Method', 'Address', 'Info');
	$altlinks = array();
	$title= 'Payment Methods';
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'filteroptions'=>array("All files"=>"all", "Files only"=>"files", "Images only"=>"images"),
		'altlinks' => $altlinks,
		'title' => $title,
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);
	
	if($settings->get_setting('checkout_type')){
		$stripe_helper = new StripeHelper();
		$stripe_customer = $stripe_helper->get_customer($user);
		

		
		if($stripe_customer['invoice_settings']['default_payment_method']){
			echo '<p>Default payment method:</p>'.$stripe_customer['invoice_settings']['default_payment_method'];
		}
		else if($stripe_customer['default_source']){
			//THIS IS APPARENTLY FOR LEGACY INTEGRATIONS
			echo '<p>Default source:</p>'.$stripe_customer['default_source'];
		}
		else{
			echo 'No default payment method.';
		}
	

	}

	foreach($methods as $method) {
		
		$rowvalues = array();
		array_push($rowvalues, $method['id']);
		array_push($rowvalues, $method['card']['display_brand'] . ' ' . $method['card']['funding'] .' '.$method['card']['last4'].'('.$method['card']['exp_month']. '/'.$method['card']['exp_year'].')');
		
		array_push($rowvalues, $method['billing_details']['address']['line1'] . ' ' . $method['billing_details']['address']['line2'] .' '.$method['billing_details']['address']['city']. ' ' . $method['billing_details']['address']['postal_code'] . ' ' . $method['billing_details']['address']['country']);
		
		array_push($rowvalues, $method['billing_details']['name'] . ' ' . $method['billing_details']['email'] .' '.$method['billing_details']['phone']);
		$page->disprow($rowvalues);
			
	}
	
	$page->endtable($pager);	


	$page->admin_footer();

?>

