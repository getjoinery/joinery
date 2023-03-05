<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	
	$settings = Globalvars::get_instance();
	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require_once $composer_dir.'autoload.php';	

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	
	$settings = Globalvars::get_instance();
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];

	if($_SESSION['test_mode'] || $settings->get_setting('debug')){
		$api_key = $settings->get_setting('stripe_api_key_test');
		$api_secret_key = $settings->get_setting('stripe_api_pkey_test');
	}
	else{
		$api_key = $settings->get_setting('stripe_api_key');
		$api_secret_key = $settings->get_setting('stripe_api_pkey');		
	}

	if(!$api_key || !$api_secret_key){
		throw new SystemDisplayablePermanentError("Stripe api keys are not present.");
		exit();			
	}

	\Stripe\Stripe::setApiKey($api_key);
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 1,
		'page_title' => 'Order Refunds',
		'readable_title' => 'Order Refunds',
		'breadcrumbs' => array(
			'Orders'=>'/admin/admin_orders', 
			'Refund order' => '',
		),
		'session' => $session,
	)
	);

	
	if ($_POST['confirm']){
		
			
		//HOW TO REFUND PART OF A CHARGE https://stripe.com/docs/refunds#issuing

		try{
			$re = \Stripe\Refund::create([
			'charge' => $_POST['charge_id'],
			'amount' => $_POST['refund_amount']*100
			]);
		}
		catch(\Stripe\Exception $e) {
			  echo 'Status is:' . $e->getHttpStatus() . '\n';
			  echo 'Type is:' . $e->getError()->type . '\n';
			  echo 'Code is:' . $e->getError()->code . '\n';
			  exit;
		}
		
		$charge = \Stripe\Charge::retrieve($_POST['charge_id']);
		
		$order_item = new OrderItem($_REQUEST['order_item_id'], TRUE); 
		$order_item->set('odi_refund_amount', $_POST['refund_amount']);
		$order_item->set('odi_refund_note', $_POST['odi_refund_note']);
		$order_item->set('odi_refund_time', 'now()');
		$order_item->save();
		
		$order = $order_item->get_order();
		$order->set('ord_refund_time', 'now()');
		$order->set('ord_refund_amount', $charge->amount_refunded/100);
		//$order->set('ord_refund_note', $_POST['ord_refund_note']);
		$order->save();


		$pageoptions['title'] = 'Refund confirm';
		$page->begin_box($pageoptions);
	
		echo $order_item->get('odi_refund_amount'). ' was refunded on order <a href="/admin/admin_order?ord_order_id='.$order->key.'">'.$order->key.'</a>';
		


	}
else{
	
	if(!$_REQUEST['oi']){
		throw new SystemDisplayablePermanentError("Order item is not present.");
		exit();			
	}
	
	$order_item = new OrderItem($_REQUEST['oi'], TRUE);
	$product = new Product($order_item->get('odi_pro_product_id'), TRUE);
	$order = $order_item->get_order();

	if ($order->get('ord_stripe_charge_id')){
		$charge_id = $order->get('ord_stripe_charge_id');
		try{
			$charge = \Stripe\Charge::retrieve($charge_id);	
		}
		catch(\Stripe\Exception $e) {
			  echo 'Status is:' . $e->getHttpStatus() . '\n';
			  echo 'Type is:' . $e->getError()->type . '\n';
			  echo 'Code is:' . $e->getError()->code . '\n';
			  exit;
		}		
	}	
	else if ($order->get('ord_stripe_payment_intent_id')) {
		try{
			$intent = \Stripe\PaymentIntent::retrieve($order->get('ord_stripe_payment_intent_id'));
			$charge_id = $intent->charges->data[0]->id;
			$charge = \Stripe\Charge::retrieve($charge_id);
		}
		catch(\Stripe\Exception $e) {
			  echo 'Status is:' . $e->getHttpStatus() . '\n';
			  echo 'Type is:' . $e->getError()->type . '\n';
			  echo 'Code is:' . $e->getError()->code . '\n';
			  exit;
		}
	}
	else{
		echo "No payment intent or charge id.";
		exit();					
	}

	$amount_refunded = 0;
	if($charge->amount_refunded){
		$amount_refunded = $charge->amount_refunded/100;
		$amount_left = ($charge->amount - $charge->amount_refunded)/100;
	}
	else{
		$amount_refunded = 0;
		$amount_left = $charge->amount/100;
	}
	
	if($amount_left > $order_item->get('odi_price')){
		$amount_left = $order_item->get('odi_price');
	}
	
	$session = SessionControl::get_instance();
	$session->set_return("/admin/admin_order_refund");



	
	$pageoptions['title'] = 'Refund charge';
	$page->begin_box($pageoptions);

	$formwriter = new FormWriterMaster("form1");
	

	$validation_rules = array();
	$validation_rules['refund_amount']['required']['value'] = 'true';
	$validation_rules['refund_amount']['max']['value'] = $amount_left;
	echo $formwriter->set_validate($validation_rules);	
	
	echo $formwriter->begin_form("form", "post", "/admin/admin_order_refund");

	echo '<fieldset><h4>Confirm Refund ('.$product->get('pro_name').')</h4>';
		echo '<div class="fields full">';	
		echo 'Total charge: ', $currency_symbol.$charge->amount/100 .'. '.$currency_symbol.$amount_refunded. ' refunded so far.';
	
	if($amount_left != 0){
		echo $formwriter->textinput("Amount to refund (".$currency_symbol.$amount_left. " maximum)", 'refund_amount',"ctrlHolder", 20, $amount_left , '', 255, NULL);	
		echo $formwriter->textinput("Refund description or reason", 'odi_refund_note',"ctrlHolder", 20, $order_item->get('odi_refund_note'), '', 255, '');	
		echo $formwriter->hiddeninput("confirm", 1);
		echo $formwriter->hiddeninput("charge_id", $charge_id);
		echo $formwriter->hiddeninput("order_item_id", $order_item->key);

		echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_buttons();
	}
	else{
		echo '<p>You cannot refund any more.</p>';
	}
	


		echo '</div>';
	echo '</fieldset>';
	echo $formwriter->end_form();



	


}
	$page->end_box();
$page->admin_footer();
?>




