<?php
require_once('Globalvars.php');
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir.'/data/users_class.php');

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

	
}

?>
