<?php
require_once('Globalvars.php');
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir.'/data/users_class.php');
require_once($siteDir.'/data/orders_class.php');
require_once($siteDir.'/data/order_items_class.php');

$composer_dir = $settings->get_setting('composerAutoLoad');	
require_once $composer_dir.'autoload.php';

class StripeHelperException extends Exception {}

class StripeHelper {
	private $api_key;
	private $api_secret_key;
	private $stripe;
	private $stripe_test;
	public $test_mode;

	public function __construct() {
		
		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();

		if($_SESSION['test_mode'] || $settings->get_setting('debug')){
			$this->api_key = $settings->get_setting('stripe_api_key_test');
			$this->api_secret_key = $settings->get_setting('stripe_api_pkey_test');
			$this->test_mode = true;
		}
		else{
			$this->api_key = $settings->get_setting('stripe_api_key');
			$this->api_secret_key = $settings->get_setting('stripe_api_pkey');
			$this->test_mode = false;			
		}

		if(!$this->api_key || !$this->api_secret_key){
			throw new SystemDisplayablePermanentError("Stripe api keys are not present.");
			exit();			
		}
		

		$this->stripe = new \Stripe\StripeClient([
			'api_key' => $this->api_key,
			'stripe_version' => '2022-11-15'
		]);	
		
	

	}


	public function get_stripe_customer_id($user) {
		if($this->test_mode){
			return $user->get('usr_stripe_customer_id_test');
		}
		else{
			return $user->get('usr_stripe_customer_id');
		}
	}

	public function get_customer($user, $return_type='object') {
		$stripe_customer = $this->stripe->customers->all(["email" => $user->get('usr_email')]);
		if($return_type == 'object'){
			if($this->test_mode){
				$user->set('usr_stripe_customer_id_test', $stripe_customer[data][0][id]);
			}
			else{
				$user->set('usr_stripe_customer_id', $stripe_customer[data][0][id]);
			}		 
			$user->save();
			return $stripe_customer;
		}
		else if($return_type == 'id'){
			if($stripe_customer[data][0][id]){
				if($this->test_mode){
					$user->set('usr_stripe_customer_id_test', $stripe_customer[data][0][id]);
				}
				else{
					$user->set('usr_stripe_customer_id', $stripe_customer[data][0][id]);
				}		 
				$user->save();
				return $stripe_customer[data][0][id];
			}
			else{
				return false;
			}
		}
		else{
			throw new SystemDisplayablePermanentError("Invalid return type.");
			exit();			
		}
	}
	
	public function get_customers($params){
		$stripe_customers = $this->stripe->customers->all($params);
		return $stripe_customers;
	}

	public function create_customer_at_stripe($user, $return_type='object') {
		$stripe_customer = $this->stripe->customers->create([
				'name' => $user->get('usr_first_name'). ' ' . $user->get('usr_last_name'),
				'email' => $user->get('usr_email'),
				'description' => $user->get('usr_first_name'). ' ' . $user->get('usr_last_name'). ' ('.$user->get('usr_email').')',
			]);
			
		if($this->test_mode){
			$user->set('usr_stripe_customer_id_test', $stripe_customer[id]);
		}
		else{
			$user->set('usr_stripe_customer_id', $stripe_customer[id]);
		}
		$user->save();

		if($return_type == 'object'){
			return $stripe_customer;
		}
		else if($return_type == 'id'){
			if($stripe_customer[id]){
				return $stripe_customer[id];
			}
			else{
				return false;
			}
		}
		else{
			throw new SystemDisplayablePermanentError("Invalid return type.");
			exit();			
		}
	}
	
	public function get_or_create_stripe_customer($user){
		if($stripe_customer_id = $this->get_stripe_customer_id($user)){
			return $stripe_customer_id;
		}
		else if($stripe_customer_id = $this->get_customer($user, 'id')){
			return $stripe_customer_id;
		}
		else{
			$stripe_customer_id = $this->create_customer_at_stripe($user, 'id');
			return $stripe_customer_id;
		}
	}
	
	public function get_charge($stripe_charge_id){
		$charge = $this->stripe->charges->retrieve($stripe_charge_id);
		return $charge;
	}
	
	public function get_charges($params){
		$charges = $this->stripe->charges->all($params);
		return $charges;
	}

	public function get_invoices($params){
		$invoices = $this->stripe->invoices->all($params);
		return $invoices;
	}
	
	public function get_payment_intent($stripe_payment_intent_id){
		$intent = $this->stripe->paymentIntents->retrieve($stripe_payment_intent_id);
		return $intent;
	}	
	
	public function get_charge_from_payment_intent($stripe_payment_intent_id){
		$intent = $this->get_payment_intent($stripe_payment_intent_id);
		$charge = $this->get_charge($intent->charges->data[0]->id);
		return $charge;
	}
	
	public function get_charge_from_order($order){
		if($order->get('ord_test_mode')){
			if(!$this->test_mode){
				//DON'T UPDATE TEST MODE ORDERS IF NOT IN TEST MODE
				return false;		
			}
		}
		else{
			if($this->test_mode){
				//DON'T UPDATE LIVE MODE ORDERS IF IN TEST MODE
				return false;			
			}			
		}
		
		if ($order->get('ord_stripe_charge_id')){
			$charge_id = $order->get('ord_stripe_charge_id');
			
			try{
				
				return $this->get_charge($charge_id);

			}
			catch(\Stripe\Exception $e) {
				  return false;
			}	
		}	
		else if ($order->get('ord_stripe_payment_intent_id')) {
			try{
				
				$charge = $this->get_charge_from_payment_intent($order->get('ord_stripe_payment_intent_id'));
				return $charge;
			}
			catch(\Stripe\Exception $e) {
				  return false;
			}
		}
			
	}
	
	public function update_order_refund_amount_from_stripe($order){
		if($order->get('ord_test_mode')){
			if(!$this->test_mode){
				//DON'T UPDATE TEST MODE ORDERS IF NOT IN TEST MODE
				return false;		
			}
		}
		else{
			if($this->test_mode){
				//DON'T UPDATE LIVE MODE ORDERS IF IN TEST MODE
				return false;			
			}			
		}
		$charge = $this->get_charge_from_order($order);

		if($charge->amount_refunded){
			//print_r($charge->refunds);
			//$order->set('ord_refund_time', 'now()');
			$order->set('ord_refund_amount', $charge->amount_refunded/100);
			//$order->set('ord_refund_note', $_POST['ord_refund_note']);

			$order->save();
	
		}	
		return $charge;		
	}

	public function get_subscription($stripe_subscription_id){
		$stripe_subscription = $this->stripe->subscriptions->retrieve($stripe_subscription_id);
		return $stripe_subscription;
	}
	
	public function get_subscriptions($params){
		$subs = $this->stripe->subscriptions->all($params);
		return $subs;
	}
	
	public function refund_charge($stripe_charge_id, $refund_amount){
		try{
			$re = $this->stripe->refunds->create([
			'charge' => $stripe_charge_id,
			'amount' => $refund_amount*100
			]);
		}
		catch(\Stripe\Exception $e) {
			  echo 'Status is:' . $e->getHttpStatus() . '\n';
			  echo 'Type is:' . $e->getError()->type . '\n';
			  echo 'Code is:' . $e->getError()->code . '\n';
			  exit;
		}		
	}
	
	public function update_all_subscriptions_in_order($order){
		$order_items = $order->get_order_items();
		foreach($order_items as $order_item){
			$this->update_subscription_in_order_item($order_item);
		}	
		return true;
	}
	
	public function update_subscription_in_order_item($order_item){
		if($order_item->get('odi_is_subscription') && !$order_item->get('odi_subscription_cancelled_time')){
			//CHECK SUBSCRIPTION STATUS
			try{		
				$stripe_subscription = $this->get_subscription($order_item->get('odi_stripe_subscription_id'));	
				if($stripe_subscription[status] == 'canceled'){
					$canceled_at = gmdate("c", $stripe_subscription[canceled_at]);
					//IF SUBSCRIPTION ENDED, REMOVE 
					$order_item->set('odi_subscription_cancelled_time', $canceled_at);
					$order_item->save();
				}
				return true;
			}
			catch(Exception $e){
				//FAIL SILENTLY
				return false;
			}
		}		
	}
	
	
	public function create_card_from_token($stripe_token, $stripe_customer_id, $set_as_default=true){
		try {
			//STORE PAYMENT METHOD 
			/*
			$source_result = $stripe->sources->create([ 
				 'type' => 'card', 
				 'token' => $_REQUEST['stripeToken'],  
			]);
			*/
			$source_result = $this->stripe->customers->createSource($stripe_customer_id, [ 
				 'source' => $stripe_token,  
			]);
	

		}
		/*
		catch(\Stripe\Error\Card $e) {
			// Since it's a decline, \Stripe\Exception\Card will be caught
			$error = "Sorry, we weren't able to charge your card. <strong>" . $e->getMessage()."</strong> Please use your back button to go back to the checkout form and try again or contact us at ".$settings->get_setting('defaultemail')." if you keep having trouble.";
			$order->set('ord_error', substr($error, 0, 250));
			$order->save();	
			PublicPageTW::OutputGenericPublicPage("Card Error", "Card Error", $error);
		} 
		catch(\Stripe\Error\CardException $e) {
			// Since it's a decline, \Stripe\Exception\Card will be caught
			$error = "Sorry, we weren't able to charge your card. <strong>" . $e->getMessage()."</strong> Please use your back button to go back to the checkout form and try again or contact us at ".$settings->get_setting('defaultemail')." if you keep having trouble.";
			$order->set('ord_error', substr($error, 0, 250));
			$order->save();	
			PublicPageTW::OutputGenericPublicPage("Card Error", "Card Error", $error);
		}
		catch (\Stripe\Exception\RateLimitException $e) {
		  // Too many requests made to the API too quickly
			$error = "Sorry, we weren't able to authorize your card due to too many requests. You have not been charged.";
		} 
		catch (\Stripe\Exception\InvalidRequestException $e) {
			$error = "Sorry, we weren't able to authorize your card due to an invalid request. That's our fault. You have not been charged.";	
		} 
		catch (\Stripe\Exception\AuthenticationException $e) {
		  // Authentication with Stripe's API failed
		  // (maybe you changed API keys recently)
		  $error = "Sorry, our connection to our credit card processor is not currently working. That's our fault. You have not been charged.";
		} 
		catch (\Stripe\Exception\ApiConnectionException $e) {
		  // Network communication with Stripe failed
		  $error = "Sorry, we were unable to reach the credit card processor. That's our fault. You have not been charged.";
		} 
		catch (\Stripe\Exception\ApiErrorException $e) {
			
			print_r($e);
			exit;
		  // Display a very generic error to the user, and maybe send
		  // yourself an email
		  $error = "Sorry, we weren't able to connect to the Stripe api.";
		} 
		*/
		catch (Exception $e) {	
			$error = "Sorry, we weren't able to store your card. " . $e->getMessage();
			error_log($error);
			PublicPageTW::OutputGenericPublicPage("Card Error", "Card Error", $error);	
			exit;
			/*		
			$stored_error = "Card not charged.   Error type: ". $e->getError()->type . "  Code: " . $e->getError()->code. "  Decline code: ". $e->getError()->decline_code . "  Message: ".$e->getMessage(). "  Debug info: ".$e->getError()->doc_url .", ". $e->getError()->param;

			$error = "Sorry, we weren't able to charge your card. <strong>" . $e->getMessage()."</strong> Please use your back button to go back to the checkout form and try again or contact us at ".$settings->get_setting('defaultemail')." if you keep having trouble.";
			$order->set('ord_error', substr($stored_error, 0, 250));
			$order->save();	
			PublicPageTW::OutputGenericPublicPage("Card Error", "Card Error", $error);
			
			$error = "Sorry, we weren't able to charge your card. " . $e->getMessage();
			exit;
			*/
		}
		
		//SET NEW CARD AS DEFAULT 
		if($set_as_default){
			try {
				$customer = $this->stripe->customers->retrieve($stripe_customer_id);
				$customer->default_source=$source_result['id'];
				$customer->save();  
			}
			catch (Exception $e) {		  
				error_log("Unable to set stripe default card.");
			}
		}			
		return $source_result;
		
	}
	
	public function get_subscription_plan($plan_name){
		$plan = $this->stripe->plans->retrieve($plan_name);
		return $plan;
	}
	
	public function create_subscription_plan($params){
		//CREATE NEW PLAN
		$plan = $this->stripe->plans->create([
		  "amount" => (int)$params['price'] * 100,
		  "interval" => $params['interval'],
		  "product" => [
			"name" => 'Subscription '.$params['currency_symbol'] . (int)$params['price'],
		  ],
		  "currency" => $params['currency_code'],
		  "id" => 'subscription-' . (int)$params['price'],
		]); 	
		return $plan;
	}
	
	public function create_subscription($params){
		$subscription = $this->stripe->subscriptions->create($params);	
		return $subscription;
	}


	public function create_charge($params){
		//CHARGE THE PURCHASE
		$charge = $this->stripe->charges->create($params); 
		return $charge;
	}


}

?>
