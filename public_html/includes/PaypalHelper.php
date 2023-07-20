<?php
require_once('Globalvars.php');
require_once('SessionControl.php');

class PaypalHelper{
	
	private $api_key;
	private $api_secret_key;
	private $return_url;
	private $cancel_url;
	private $endpoint;
	public $test_mode;
	public $currency = 'USD';

	public function __construct() {
		
		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();

		if($_SESSION['test_mode'] || $settings->get_setting('debug')){
			$this->api_key = $settings->get_setting('paypal_api_key_test');
			$this->api_secret_key = $settings->get_setting('paypal_api_secret_test');
			$this->endpoint = 'https://api-m.sandbox.paypal.com';
			$this->test_mode = true;
		}
		else{
			$this->api_key = $settings->get_setting('paypal_api_key');
			$this->api_secret_key = $settings->get_setting('paypal_api_secret');
			$this->endpoint = 'https://api-m.paypal.com';
			$this->test_mode = false;			
		}

		if(!$this->api_key || !$this->api_secret_key){
			throw new SystemDisplayablePermanentError("Paypal api keys are not present.");
			exit();			
		}
		
		$this->return_url = $settings->get_setting('webDir').'/cart_charge';
		$this->cancel_url = $settings->get_setting('webDir').'/cart';

	}
	
	public function build_item_array($cart_items){
		//TODO: QUANTITY $item['quantity'], RECURRING $item['recurring']

		$purchase_units = array();
		foreach ($cart_items as $item) {
			if($item['recurring']){
				//PAYPAL DOES NOT WORK WITH RECURRING ITEMS
				return false;
			}
			$purchase_unit = array();
			$purchase_unit['reference_id'] = $item['name'];

			$amount = array();
			$amount['currency_code'] = $this->currency;
			$amount['value'] = (int)($item['price'] - $item['discount']);

			$purchase_unit['amount'] = $amount;

			$purchase_units[] = $purchase_unit;
		}

		$intent = "CAPTURE";

		$data = [
			"intent" => $intent,
			"purchase_units" => $purchase_units,
		];	

		return $data;
		
	}
	
	public function output_paypal_checkout_code($data){ 
		if(!$data){
			return false;
		}
		$output = '
		<div class="flex justify-end"><div class="inline-flex justify-end mt-3 mr-3 border border-transparent shadow-sm text-sm font-medium rounded-md text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 ">
			<div id="paypal-button-container"></div>
		  </div></div>

		  <script src="https://www.paypal.com/sdk/js?client-id='.$this->api_key.'&enable-funding=venmo&disable-funding=paylater"></script>
		  <script>
			let paypalData = '. json_encode($data) .';
			paypal.Buttons({
			  createOrder: function(data, actions) {
				return actions.order.create(paypalData).then(function(orderID) {
				  console.log(orderID);
				  return orderID;
				});
			  },
			  onApprove: function(data, actions) {
				return actions.order.capture().then(function(details) {
				  // Pass the captured ID to the return URL page
				  window.location.href = "'.$this->return_url.'?id=" + details.id;
				});
			  },
			  onError: function(err) {
				alert("An error occurred during the payment. Please try again.");
			  }
			}).render("#paypal-button-container");
		  </script>	';
		  return $output;
		
	}
	
	//UNUSED IN CURRENT IMPLEMENTATION
	public function createPayment($data){
		
		$access_token=$this->getAccessToken();
		$curl = curl_init();
		
		curl_setopt_array($curl, array(
		  CURLOPT_URL => $this->endpoint.'/v2/checkout/orders',
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_ENCODING => '',
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 0,
		  CURLOPT_FOLLOWLOCATION => true,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_CUSTOMREQUEST => 'POST',
		  CURLOPT_POSTFIELDS =>json_encode($data),
		  CURLOPT_HTTPHEADER => array(
			'Content-Type: application/json',
			"Authorization: Basic $access_token"
		  ),
		));

		$response = curl_exec($curl);

		curl_close($curl);
		return json_decode($response,true);

	}
	
	//UNUSED IN CURRENT IMPLEMENTATION
	public function capturePayment($payment_id){
		$curl = curl_init();
		$access_token=$this->getAccessToken();
		curl_setopt_array($curl, array(
		  CURLOPT_URL => $this->endpoint."/v2/checkout/orders/$payment_id/capture",
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_ENCODING => '',
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 0,
		  CURLOPT_FOLLOWLOCATION => true,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_CUSTOMREQUEST => 'POST',
		  CURLOPT_POSTFIELDS =>'{}',
		  CURLOPT_HTTPHEADER => array(
			'Content-Type: application/json',
			"Authorization: Basic $access_token"
		  ),
		));

		$response = curl_exec($curl);

		curl_close($curl);
		return json_decode($response,true);
	}
	
    public function validatePayment($payment_id){
		
		$access_token=$this->getAccessToken();
		$curl = curl_init();

		curl_setopt_array($curl, array(
		  CURLOPT_URL => $this->endpoint.'/v2/checkout/orders/'.$payment_id,
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_ENCODING => '',
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 0,
		  CURLOPT_FOLLOWLOCATION => true,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_CUSTOMREQUEST => 'GET',
		  CURLOPT_HTTPHEADER => array(
			"Authorization: Basic $access_token"
		   ),
		));

		$response = curl_exec($curl);

		curl_close($curl);
		return json_decode($response,true);

	}
	
	
	
	protected function getAccessToken(){
		return $access_token=base64_encode($this->api_key.':'.$this->api_secret_key);
	}
	
	
}