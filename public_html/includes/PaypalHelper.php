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

		if(StripeHelper::isTestMode()){
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
		
		$this->return_url = LibraryFunctions::get_absolute_url('/cart_charge');
		$this->cancel_url = LibraryFunctions::get_absolute_url('/cart');

	}
	
	public function build_item_array($cart_items){
		//TODO: QUANTITY $item['quantity'], RECURRING $item['recurring']

		$purchase_units = array();
		foreach ($cart_items as $item) {
			if(!$item['recurring']){

				$purchase_unit = array();
				$purchase_unit['reference_id'] = $item['name'];

				$amount = array();
				$amount['currency_code'] = $this->currency;
				$amount['value'] = ($item['price'] - $item['discount']);

				$purchase_unit['amount'] = $amount;

				$purchase_units[] = $purchase_unit;
			}
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

		$settings = Globalvars::get_instance();
		$venmo_param = '';
		if ($settings->get_setting('use_venmo_checkout') && strtoupper($settings->get_setting('site_currency')) === 'USD') {
			$venmo_param = '&enable-funding=venmo';
		}

		$output = '
		<div class="flex justify-end"><div class="inline-flex justify-end mt-3 mr-3 border border-transparent shadow-sm text-sm font-medium rounded-md text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 ">
			<div id="paypal-button-container"></div>
		  </div></div>

		  <script src="https://www.paypal.com/sdk/js?client-id='.$this->api_key.$venmo_param.'&disable-funding=paylater"></script>
		  <script>
			var selectedFundingSource = "paypal";
			let paypalData = '. json_encode($data) .';
			paypal.Buttons({
			  createOrder: function(data, actions) {
				return actions.order.create(paypalData).then(function(orderID) {
				  console.log(orderID);
				  return orderID;
				});
			  },
			  onClick: function(data) {
				selectedFundingSource = data.fundingSource || "paypal";
			  },
			  onApprove: function(data, actions) {
				return actions.order.capture().then(function(details) {
				  var fundingSource = data.paymentSource || selectedFundingSource || "paypal";
				  window.location.href = "'.$this->return_url.'?id=" + details.id + "&funding=" + encodeURIComponent(fundingSource);
				});
			  },
			  onError: function(err) {
				alert("An error occurred during the payment. Please try again.");
			  }
			}).render("#paypal-button-container");
		  </script>	';
		  return $output;

	}
	
	public function output_paypal_subscription_checkout_code($plan_id){ 
	
		$output = '
		<div class="flex justify-end"><div class="inline-flex justify-end mt-3 mr-3 border border-transparent shadow-sm text-sm font-medium rounded-md text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 ">
			<div id="paypal-button-container"></div>
		  </div></div>
 <script src="https://www.paypal.com/sdk/js?client-id='.$this->api_key.'&intent=subscription&vault=true&disable-funding=paylater">
   </script>
 
   <script>
    paypal.Buttons({
      createSubscription: function(data, actions) {
        return actions.subscription.create({
          "plan_id": "'.$plan_id.'",
        });
      },
      onApprove: function(data, actions) {    
		// Go to the return URL page
		window.location.href = "'.$this->return_url.'?subscription=1";
      },
	  onError: function(err) {
		alert("An error occurred during the payment. Please try again.");
	  }
    }).render("#paypal-button-container"); 
  </script>';
		  
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
	

	public function searchProduct($product_title){
		$response=$this->listProduct();
		
		$products=$response['products'];
		foreach ($products as $product){
			
			if($product['name'] == $product_title){
				return $product;
			}
		}
		
		return false;
	}	
	
	
	// get product list:-
	public function listProduct(){
		$access_token = $this->getAccessToken();	
		$curl=curl_init();
		curl_setopt_array($curl, array(
		  CURLOPT_URL => $this->endpoint.'/v1/catalogs/products?page_size=100',
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
		return json_decode($response, true);
	}
	
	
	//PAYPAL PRODUCT CREATION
	public function createProduct($product){
		$access_token = $this->getAccessToken();
			$settings = Globalvars::get_instance();
			$webDir = $settings->get_setting('webDir');
		
		// Prepare the data as an associative array
	
		$postData = array(
			"name" => $product->get('pro_name'), 
			"description" => $product->get('pro_description'),
			"type" => "DIGITAL",  //PHYSICAL, DIGITAL, OR SERVICE
			//"category" => "SOFTWARE",  //Category, see:  https://developer.paypal.com/docs/api/catalog-products/v1/#products_create
			//"image_url" => "https://example.com/streaming.jpg",
			"home_url" => LibraryFunctions::get_absolute_url($product->get_url()),
		);

	 
		$jsonData = json_encode($postData);

		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $this->endpoint.'/v1/catalogs/products',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => $jsonData,
			CURLOPT_HTTPHEADER => array(
				'Content-Type: application/json',
				"Authorization: Basic $access_token"
			),
		));

		$response = curl_exec($curl);
		curl_close($curl);

		$response = json_decode($response, true);
		return $response;
	}


	public function searchPlans($title){
		$response=$this->listPlans();
		$plans=$response['plans'];
		foreach ($plans as $plan){
			
			if($plan['name'] == $title){
				return $plan;
			}
		}
		
		return false;
	}	
	
	
	// get product list:-
	public function listPlans(){
		$access_token = $this->getAccessToken();	
		$curl=curl_init();
		curl_setopt_array($curl, array(
		  CURLOPT_URL => $this->endpoint.'/v1/billing/plans',
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
		return json_decode($response, true);
	}

	//PAYPAL PLAN CREATION
    public function createPlan($stripe_product_id, $product_version, $amount){
		$product = new Product($product_version->get('prv_pro_product_id'), TRUE);
		$access_token = $this->getAccessToken();
		
		
		$interval_unit = strtoupper($product_version->is_subscription());
		
		$jsonData = array(
			"product_id" => $stripe_product_id,
			"name" => $product->get('pro_name') . '-' . $amount, // you can  change the plan name
			//"description" => "Premium Plan",
			"status" => "ACTIVE",
			"billing_cycles" => array(
				/*array(
					"frequency" => array(
						"interval_unit" => "MONTH",
						"interval_count" => 1
					),
					"tenure_type" => "TRIAL",
					"sequence" => 1,
					"total_cycles" => 2,
					"pricing_scheme" => array(
						"fixed_price" => array(
							"value" => "3", 
							"currency_code" => "USD"
						)
					)
				),
				array(
					"frequency" => array(
						"interval_unit" => "MONTH",
						"interval_count" => 1
					),
					"tenure_type" => "TRIAL",
					"sequence" => 2,
					"total_cycles" => 3,
					"pricing_scheme" => array(
						"fixed_price" => array(
							"value" => "6", 
							"currency_code" => "USD"
						)
					)
				),*/
				array(
					"frequency" => array(
						"interval_unit" => $interval_unit,
						"interval_count" => 1
					),
					"tenure_type" => "REGULAR",
					"sequence" => 1,
					"total_cycles" => 0,
					"pricing_scheme" => array(
						"fixed_price" => array(
							"value" => $amount, 
							"currency_code" => "USD"
						)
					)
				)
			),
			"payment_preferences" => array(
				"auto_bill_outstanding" => true,
				/*"setup_fee" => array(
					"value" => "10",
					"currency_code" => "USD"
				),
				"setup_fee_failure_action" => "CONTINUE",*/
				"payment_failure_threshold" => 3
			),
			/*"taxes" => array(
				"percentage" => "10",
				"inclusive" => false
			)*/
		);

		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $this->endpoint.'/v1/billing/plans',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => json_encode($jsonData),
			CURLOPT_HTTPHEADER => array(
				'Content-Type: application/json',
				"Authorization: Basic $access_token"
			),
		));

		$response = curl_exec($curl);
		curl_close($curl);
		return json_decode($response,true);
    }
	
	// function to create Subscription:-
	public function createSubscription($plan_id) {
		$access_token = $this->getAccessToken();
		$subscription_url = $this->endpoint.'/v1/billing/subscriptions';

		$subscription_data = array(
			'plan_id' => $plan_id,
			'start_time' => date('Y-m-d\TH:i:s\Z', strtotime('+1 day')),
			'quantity' => 1,
			/*'shipping_amount' => array(
				'currency_code' => 'USD',
				'value' => '10.00', 
			),*/
			'application_context' => array( 
				"return_url" => $this->return_url,
				"cancel_url" => $this->cancel_url,
			), 
		);

		$json_data = json_encode($subscription_data);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $subscription_url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			"Authorization: Basic $access_token"
		));

		$result = curl_exec($ch);
		curl_close($ch);			
		return json_decode($result, true);
	}
	
	// function to get subscriptions details:-
	public function subDetails($sub_id){
		$access_token = $this->getAccessToken();
		$curl = curl_init();

		curl_setopt_array($curl, array(
		  CURLOPT_URL => $this->endpoint.'/v1/billing/subscriptions/'.$sub_id,
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
		return json_decode($response, true);
	}

	
	
	
	protected function getAccessToken(){
		return $access_token=base64_encode($this->api_key.':'.$this->api_secret_key);
	}
	
	
}