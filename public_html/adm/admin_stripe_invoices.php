<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('/includes/AdminPage.php');
	
	PathHelper::requireOnce('/includes/LibraryFunctions.php');

	PathHelper::requireOnce('/data/orders_class.php');
	PathHelper::requireOnce('/data/stripe_invoices_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();
	
	$settings = Globalvars::get_instance();
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];

	$numperpage = 60;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'siv_stripe_invoice_id', 0, '');	
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	
	$user_id = LibraryFunctions::fetch_variable('u', NULL, 0, '');
	
	$search_criteria = NULL;
	if($user_id){
		$search_criteria = array();
		if($user_id){
			$search_criteria['user_id'] = $user_id;
		}
	}

	$stripe_invoices = new MultiStripeInvoice(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);
	$numrecords = $stripe_invoices->count_all();
	$stripe_invoices->load();
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'stripe-payments',
		'breadcrumbs' => array(
			'Orders'=>'/admin/admin_orders', 
			'Stripe Invoices' => ''
		),
		//'page_title' => 'Event Sessions',
		//'readable_title' => 'Event Sessions',
		'session' => $session,
	)
	);	

	
	$headers = array('Invoice ID', 'User', 'Amount', 'Subscription ID', 'Description', 'Date');
	$altlinks = array();
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Stripe Invoices',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);
	
	foreach($stripe_invoices as $stripe_invoice) {
		$rowvalues = array();
		array_push($rowvalues, $stripe_invoice->key);	
		
		if($stripe_invoice->get('siv_usr_user_id')){
			$user = new User($stripe_invoice->get('siv_usr_user_id'), TRUE);
			array_push($rowvalues, '<a href="/admin/admin_user?usr_user_id=' . $user->key . '">' . $user->display_name() . '</a>');
		}
		else{
			$user = new User(NULL);
			array_push($rowvalues, '-');
		}		
		
		array_push($rowvalues, $stripe_invoice->get('siv_amount_paid'));
		array_push($rowvalues, $stripe_invoice->get('siv_stripe_subscription_id'));
		array_push($rowvalues, $stripe_invoice->get('siv_description'));	
		

		




		array_push($rowvalues,  LibraryFunctions::convert_time($stripe_invoice->get('siv_timestamp'), "UTC", $session->get_timezone(), 'M j, Y'));


		
		
		$page->disprow($rowvalues);

	}
	$page->endtable($pager);		
	
	$page->admin_footer();

?>
