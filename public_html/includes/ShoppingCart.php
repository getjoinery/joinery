<?php
require_once('Globalvars.php');
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/StripeHelper.php');
require_once($siteDir.'/data/products_class.php');
require_once($siteDir.'/data/product_groups_class.php');
require_once($siteDir.'/data/users_class.php');
require_once($siteDir.'/data/orders_class.php');
require_once($siteDir.'/data/order_items_class.php');
require_once($siteDir.'/data/event_registrants_class.php');

class ShoppingCartException extends Exception {}

class ShoppingCart {
	public $items;
	public $billing_user;
	public $last_receipt;
	public $coupon_code;

	public function __construct() {
		$this->items = array();
		$this->extras = array();
		$this->item_id = 0;
	}

	private function get_next_item_id() {
		return $this->item_id++;
	}

	public function set_extra_info($data) {
		$this->extras = $data;
	}

	public function clear_extra_info() {
		$this->extras = array();
	}

	public function get_extra_info() {
		return $this->extras;
	}

	public function add_item($product, $form_data) {

		// First lets validate we can add this item to the cart!
		$current_count = 0;
		if ($product->get('pro_max_cart_count')) {
			// Check to make sure we haven't gone over this item's maximum purchase count
			foreach($this->get_items() as $item) {
				list ($unused_id, $item_quantity, $item_product) = $item;
				if ($item_product->key == $product->key) {
					$current_count += $item_quantity;
				}
			}

			if ($current_count >= $product->get('pro_max_cart_count')) {
				throw new ShoppingCartException(
					'Sorry, you can not add this item to you cart more than ' . $product->get('pro_max_cart_count') 
					. (($product->get('pro_max_cart_count') == 1) ? ' time' : ' times') . '.  <a href="/cart">
					View your current shopping cart</a> for more details.');
			}
		}

		if ($product->get('pro_prg_product_group_id')) {
			$product_group = new ProductGroup($product->get('pro_prg_product_group_id'), TRUE);
			$current_count = 0;
			if ($product_group->get('prg_max_items')) {
				foreach($this->get_items() as $item) {
					list ($unused_id, $item_quantity, $item_product) = $item;
					if ($item_product->get('pro_prg_product_group_id') == $product->get('pro_prg_product_group_id')) {
						$current_count += $item_quantity;
					}
				}
			}

			if ($current_count >= $product_group->get('prg_max_items')) {
				throw new ShoppingCartException($product_group->get('prg_error'));
			}
		}

		$product_version = $product->get_product_version($form_data);
		$price = $product->get_price($product_version, $form_data);

		//HANDLE COUPONS
		$settings = Globalvars::get_instance();
		if($settings->get_setting('coupons_active')){
			$discount = 0;
			if($this->coupon_code){
				if($coupon_obj = $product->has_coupon($this->coupon_code)){
					$discount = $coupon_obj->get_discount($price);
					//NO COUPONS GREATER THAN THE ITEM PRICE
					if($discount > $price){
						$discount = $price;
					}

				}
			}
		}

		$this->items[] = array(1,	$product,	$form_data, $price, $discount);
	}
	
	public function update_items_for_coupon(){

		foreach($this->items as $key => $cart_item) {
			list($quantity, $product, $data, $price, $discount) = $cart_item;
			$product_version = $product->get_product_version($data);
			$price = $product->get_price($product_version, $data);

			if($this->coupon_code){
				if($coupon_obj = $product->has_coupon($this->coupon_code)){
					$coupon_discount = $coupon_obj->get_discount($price);
					//NO COUPONS GREATER THAN THE ITEM PRICE
					if($coupon_discount > $price){
						$coupon_discount = $price;
					}
				}
			}


			$this->items[$key][4] = $coupon_discount;
		}
	}

	public function count_items() {
		return count($this->items);
	}

	public function get_items() {
		$item_array = array();
		foreach($this->items as $key => $cart_item) {
			$item_array[] = array($key, $cart_item[0], $cart_item[1]);
		}
		return $item_array;
	}
	
	public function get_items_generic() {
		$item_array = array();
		foreach($this->items as $key => $cart_item) {
			list($quantity, $product, $data, $price, $discount) = $cart_item;
			$item_array[] = array($key, $quantity, (array)$product, (array)$data);
		}
		return $item_array;
	}	

	public function get_detailed_items() {
		$detailed_items = array();
		foreach ($this->items as $key => $cart_item) {
			list($quantity, $product, $data, $price, $discount) = $cart_item; 
			$product_version = $product->get_product_version($data);
			if ($product_version !== NULL) {
				$name = $product->get('pro_name') . ' - ' . $product_version->prv_version_name;
			} else {
				$name = $product->get('pro_name');
			}

			$detailed_items[] = array(
				'id' => $key,
				'name' => $name,
				'price' => $price,
				'discount' => $discount,
				'quantity' => $quantity,
				'total' => $quantity * $price,
				'recurring' => $product->get('pro_recurring'),
			);
		}
		return $detailed_items;
	}
	
	public function get_or_create_billing_user(){
		$charge_total = $this->get_total();


		//HANDLE THE BILLING USER
		$billing_user = User::GetByEmail(trim($this->billing_user['billing_email'])); 
		if(!$billing_user){
			$cart_billing_user = $this->billing_user;
			//CREATE THE USER
			$data = array(
				'usr_first_name' => $cart_billing_user['billing_first_name'],
				'usr_last_name' => $cart_billing_user['billing_last_name'],
				'usr_email' => $cart_billing_user['billing_email'],
				'password' => NULL,
				'send_emails' => true
			);
			$billing_user = User::CreateNew($data);			
		}	

		if($charge_total > 0){ 
			$stripe_helper = new StripeHelper();
			$stripe_customer_id = $stripe_helper->get_or_create_stripe_customer($billing_user);
		}	

		return $billing_user;
	}

	public function get_total() {
		$total_price = 0;
		foreach($this->get_detailed_items() as $cart_item) {
			$this_item_price = $cart_item['total'] -  $cart_item['discount'];
			$total_price += $this_item_price;
		}
		return $total_price;
	}
	
	public function get_recurring_total() {
		$total_price = 0;
		foreach($this->get_detailed_items() as $cart_item) {
			if($cart_item['recurring']){
				$this_item_price = $cart_item['total'] -  $cart_item['discount'];
				$total_price += $this_item_price;
			}
		}
		return $total_price;
	}
	
	public function get_non_recurring_total() {
		$total_price = 0;
		foreach($this->get_detailed_items() as $cart_item) {
			if(!$cart_item['recurring']){
				$this_item_price = $cart_item['total'] -  $cart_item['discount'];
				$total_price += $this_item_price;
			}
		}
		return $total_price;
	}
	
	public function get_num_recurring() {
		$num_recurring = 0;
		foreach($this->get_detailed_items() as $cart_item) {
			if($cart_item['recurring']){
				$num_recurring++;
			}
		}
		return $num_recurring;
	}
	
	public function get_num_non_recurring() {
		$num_non_recurring = 0;
		foreach($this->get_detailed_items() as $cart_item) {
			if(!$cart_item['recurring']){
				$num_non_recurring++;
			}
		}
		return $num_non_recurring;
	}

	public function remove_item($item_id) {
		foreach($this->items as $key => $cart_item) {
			if ($key === $item_id) {
				unset($this->items[$key]);
				return TRUE;
			}
		}
		return FALSE;
	}

	public function get_hash() {
		// Return a hash of this shopping cart, so between pages we can compare and make
		// sure the contents of the cart haven't been changed
		$hash_string = '';
		foreach($this->items as $cart_item) {
			$hash_string .= serialize($cart_item);
		}
		return md5($hash_string);
	}

	public function clear_cart() {
		$this->items = array();
		$this->item_id = 0;
		$this->coupon_code = NULL;
	}

}

?>
