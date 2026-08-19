<?php

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/product_groups_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));

class ShoppingCartException extends Exception {}

class ShoppingCart {
	public $items;
	public $billing_user;
	public $last_receipt;
	public $coupon_codes = array();
	public $extras = array();
	public $item_id = 0;

	/** Session keys for the marketing-coupon intake (store-owned). */
	const COUPON_PENDING_KEY = 'pending_coupon';
	const COUPON_FLASH_KEY   = 'pending_coupon_flash';

	/**
	 * Session slot for the cart. The value stored there is a PLAIN array
	 * (scalars + product ids), never a ShoppingCart or Product instance — see
	 * current().
	 */
	const SESSION_KEY = 'shopping_cart';

	/** In-request cache of the hydrated cart so repeated current() calls share state. */
	private static $active = null;

	public function __construct() {
		$this->items = array();
		$this->extras = array();
		$this->item_id = 0;
	}

	/**
	 * The active cart for this session.
	 *
	 * The session holds only PLAIN data (item product ids, form data, coupons)
	 * — never a serialized ShoppingCart or Product. That matters because the
	 * session is unserialized at session_start(), which runs before the store
	 * plugin's classes are loaded: any store object placed in the session would
	 * come back as __PHP_Incomplete_Class and be silently discarded, emptying
	 * the cart on every request. Storing plain data makes cart state
	 * independent of class-load order. current() rehydrates a live instance
	 * (rebuilding Product/ProductVersion objects from their ids); mutations
	 * write plain data back via persist().
	 */
	public static function current() {
		if (self::$active instanceof ShoppingCart) {
			return self::$active;
		}
		$raw = isset($_SESSION[self::SESSION_KEY]) ? $_SESSION[self::SESSION_KEY] : null;
		self::$active = is_array($raw) ? self::hydrate($raw) : new ShoppingCart();
		return self::$active;
	}

	/**
	 * Write the cart back to the session as plain data. Every mutating method
	 * calls this so cart state survives the next request's session_start()
	 * regardless of when the store's classes load.
	 */
	public function persist() {
		$_SESSION[self::SESSION_KEY] = $this->to_session_data();
	}

	/**
	 * Flatten the cart to plain arrays/scalars for session storage. Each item
	 * keeps its integer key (remove_item/update_item index by it) and stores
	 * the product + version ids rather than the loaded objects.
	 */
	public function to_session_data() {
		$items = array();
		foreach ($this->items as $key => $item) {
			list($quantity, $product, $form_data, $price, $discount, $product_version) = $item;
			$items[$key] = array(
				'quantity'   => $quantity,
				'product_id' => ($product && $product->key) ? $product->key : null,
				'version_id' => ($product_version && $product_version->key) ? $product_version->key : null,
				'form_data'  => $form_data,
				'price'      => $price,
				'discount'   => $discount,
			);
		}
		return array(
			'items'        => $items,
			'coupon_codes' => $this->coupon_codes,
			'billing_user' => $this->billing_user,
			'extras'       => is_array($this->extras) ? $this->extras : array(),
			'item_id'      => (int)$this->item_id,
			'last_receipt' => $this->last_receipt,
		);
	}

	/**
	 * Rebuild a live cart from the plain session data written by to_session_data().
	 * Products/versions are re-loaded from the database by id; an item whose
	 * product can no longer be loaded is dropped rather than fataling.
	 */
	public static function hydrate(array $data) {
		$cart = new ShoppingCart();
		$cart->coupon_codes = (isset($data['coupon_codes']) && is_array($data['coupon_codes'])) ? $data['coupon_codes'] : array();
		$cart->billing_user = isset($data['billing_user']) ? $data['billing_user'] : null;
		$cart->extras       = (isset($data['extras']) && is_array($data['extras'])) ? $data['extras'] : array();
		$cart->item_id      = isset($data['item_id']) ? (int)$data['item_id'] : 0;
		$cart->last_receipt = isset($data['last_receipt']) ? $data['last_receipt'] : null;
		$cart->items        = array();

		if (!empty($data['items']) && is_array($data['items'])) {
			foreach ($data['items'] as $key => $sdata) {
				if (empty($sdata['product_id'])) {
					continue;
				}
				try {
					$product = new Product($sdata['product_id'], true);
					if (!$product->key) {
						continue;
					}
					$version = $product->get_product_versions(true, $sdata['version_id']);
					$cart->items[$key] = array(
						$sdata['quantity'],
						$product,
						isset($sdata['form_data']) ? $sdata['form_data'] : array(),
						$sdata['price'],
						$sdata['discount'],
						$version,
					);
				} catch (Exception $e) {
					error_log('ShoppingCart::hydrate dropped item ' . $key . ': ' . $e->getMessage());
				}
			}
		}
		return $cart;
	}

	/**
	 * Marketing-coupon intake — reads ?coupon=CODE from the query string,
	 * validates against CouponCode, stashes a pending code for the next cart,
	 * and logs every attempt (valid or invalid) for attribution. Invalid codes
	 * fail silently so stale marketing links don't surface errors. Called from
	 * the store's request bootstrap (serve.php).
	 */
	public static function capture_marketing_coupon($session) {
		$code = isset($_GET['coupon']) ? trim(strtolower($_GET['coupon'])) : '';
		if ($code === '' || strlen($code) > 64) {
			return;
		}

		require_once(PathHelper::getIncludePath('plugins/store/data/coupon_codes_class.php'));
		require_once(PathHelper::getIncludePath('data/visitor_events_class.php'));

		$coupon = CouponCode::GetByColumn('ccd_code', $code);
		$valid  = $coupon && $coupon->is_valid();

		try {
			$session->save_visitor_event(VisitorEvent::TYPE_COUPON_ATTEMPT, FALSE, NULL, NULL, $code);
		} catch (Exception $e) {
			error_log('capture_marketing_coupon log error: ' . $e->getMessage());
		}

		if (!$valid) {
			return;
		}

		$_SESSION[self::COUPON_PENDING_KEY] = $code;
		$_SESSION[self::COUPON_FLASH_KEY]   = 'Coupon <strong>' . htmlspecialchars(strtoupper($code), ENT_QUOTES, 'UTF-8') . '</strong> will be applied at checkout.';

		$cart = self::current();
		if ($cart && $cart->count_items() > 0) {
			self::apply_pending_coupon($cart);
		}
	}

	/**
	 * Apply a previously-captured pending coupon to a cart. Called from
	 * add_item() so newly-added items pick up the discount. Clears the pending
	 * key on success so a manual removal sticks.
	 */
	public static function apply_pending_coupon($cart) {
		if (empty($_SESSION[self::COUPON_PENDING_KEY])) {
			return;
		}
		$result = $cart->add_coupon($_SESSION[self::COUPON_PENDING_KEY]);
		if ($result === 1) {
			unset($_SESSION[self::COUPON_PENDING_KEY]);
		}
	}

	/**
	 * Flash message for pricing/cart views after a ?coupon= URL lands a valid
	 * code. Returns an HTML string or null; clears on read so it shows once.
	 */
	public static function pending_coupon_flash() {
		if (empty($_SESSION[self::COUPON_FLASH_KEY])) {
			return null;
		}
		$msg = $_SESSION[self::COUPON_FLASH_KEY];
		unset($_SESSION[self::COUPON_FLASH_KEY]);
		return $msg;
	}

	private function get_next_item_id() {
		return $this->item_id++;
	}

	public function set_extra_info($data) {
		$this->extras = $data;
		$this->persist();
	}

	public function clear_extra_info() {
		$this->extras = array();
		$this->persist();
	}

	public function get_extra_info() {
		return $this->extras;
	}
	
	public function can_add_to_cart($product_version){
		//PRODUCT MUST HAVE A PRODUCT VERSION
		if(!$product_version->key){
			return false;
		}

		return true;
	}

	/**
	 * Check if PayPal is available for the current cart contents.
	 * PayPal cannot handle: mixed subscription + non-subscription items, or multiple subscriptions.
	 * This is checked at payment time, not at add-to-cart time, so Stripe users aren't restricted.
	 */
	public function is_paypal_available(){
		$num_recurring = $this->get_num_recurring();
		$num_non_recurring = $this->get_num_non_recurring();

		// PayPal can handle: all non-recurring, or exactly one subscription alone
		if ($num_recurring == 0) return true;
		if ($num_recurring == 1 && $num_non_recurring == 0) return true;
		return false;
	}

	public function add_item($product, $form_data) {
		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();

		$product_version = $product->get_product_versions(TRUE, $form_data['product_version']);

		// First lets validate we can add this item to the cart!
		// DO NOT ALLOW THE CART TO HOLD RECURRING AND NON RECURRING AT THE SAME time
		if(!$this->can_add_to_cart($product_version)){
			throw new ShoppingCartException(
					'Sorry, the cart may contain only one subscription, and it cannot be mixed with other items.  Remove the other items or the subscription or check out with those first. <a href="/cart">Return to the cart</a>');
		}

		// Ensure Stripe price ID exists if Stripe is enabled
		$checkout_type = $settings->get_setting('checkout_type');
		if ($checkout_type == 'stripe_regular' || $checkout_type == 'stripe_checkout') {
			$stripe_helper = new StripeHelper();
			try {
				$stripe_helper->get_or_create_price($product_version);
			} catch (Exception $e) {
				throw new ShoppingCartException('Unable to process this product: ' . $e->getMessage());
			}
		}
		
		
		$max_subscriptions = $settings->get_setting('max_subscriptions_per_user');

		
		//ENFORCE THE RESTRICTION OF MAXIMUM NUMBER OF SUBSCRIPTIONS PER USER
		//DO NOT CHECK IF THERE IS NO USER PASSED IN
		
		if($product_version->is_subscription() && $max_subscriptions){
			$num_subscriptions = 0;
			
			if($session->get_user_id()){
				//IF USER IS LOGGED IN
				$active_subscriptions = new MultiOrderItem(
				array('user_id' => $session->get_user_id(), 'is_active_subscription' => true), //SEARCH CRITERIA
				array('order_item_id' => 'DESC'),  // SORT, SORT DIRECTION
				15, //NUMBER PER PAGE
				NULL //OFFSET
				);
				$num_subscriptions = $active_subscriptions->count_all();	
			}
			else if($form_data['email'] && $user = User::GetByEmail($form_data['email'])){
				//IF USER IS NOT LOGGED IN
				$active_subscriptions = new MultiOrderItem(
				array('user_id' => $user->key, 'is_active_subscription' => true), //SEARCH CRITERIA
				array('order_item_id' => 'DESC'),  // SORT, SORT DIRECTION
				15, //NUMBER PER PAGE
				NULL //OFFSET
				);
				$num_subscriptions = $active_subscriptions->count_all();				
				
			}
			
		
			$num_subscriptions += $this->get_num_recurring();
			
			if($num_subscriptions >= $max_subscriptions){
				throw new ShoppingCartException(
					'Sorry, you can not have more than ' . $max_subscriptions . ' subscriptions.  Go back to the <a href="/cart">shopping cart</a> and remove a subscription to add another.');				
			}
		}

			
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

		//OWN-ONCE PRODUCTS: refuse what would double-sell an ownership. Every
		//check is against the account the line is FOR — the recipient on a line
		//that carries an email, the signed-in buyer otherwise — matching who
		//the payment-time recorder credits, so an owner can still buy a gift.
		//Unknown identity passes; the charge-time guard is the authority.
		$ownership_tag = trim((string)$product->get('pro_ownership_tag'));
		if ($ownership_tag !== '' && !$product_version->is_subscription()) {
			$added_for = strtolower(trim((string)($form_data['email'] ?? '')));

			foreach($this->items as $existing_item) {
				$item_product = $existing_item[1];
				$item_data = $existing_item[2];
				$item_tag = trim((string)$item_product->get('pro_ownership_tag'));
				if ($item_tag === '') {
					continue;
				}
				//A line for a different recipient is a different ownership —
				//two gifts of the same thing to two people is a fine order.
				if (strtolower(trim((string)($item_data['email'] ?? ''))) !== $added_for) {
					continue;
				}
				if ($item_tag === $ownership_tag) {
					throw new ShoppingCartException(
						'"' . htmlspecialchars($product->get('pro_name')) . '" can only be owned once, and it is already in your cart. '
						. '<a href="/cart">View your shopping cart</a>.');
				}
				//A bundle covers every tag: buying one beside a product it
				//covers would sell the same ownership twice in one order.
				if ($item_tag === Ownership::TAG_ALL || $ownership_tag === Ownership::TAG_ALL) {
					throw new ShoppingCartException(
						'"' . htmlspecialchars($product->get('pro_name')) . '" and "' . htmlspecialchars($item_product->get('pro_name'))
						. '" cannot be bought together — one is a bundle that already covers the other. '
						. '<a href="/cart">View your shopping cart</a>.');
				}
			}

			if ($added_for !== '') {
				//A recipient email no account matches owns nothing yet.
				$ownership_user = User::GetByEmail($added_for);
				$ownership_user_id = $ownership_user ? $ownership_user->key : NULL;
			}
			else {
				$ownership_user_id = $session->get_user_id() ?: NULL;
			}

			if ($ownership_user_id && Ownership::user_owns($ownership_user_id, $ownership_tag)) {
				if ($ownership_user_id == $session->get_user_id()) {
					throw new ShoppingCartException(
						'You already own "' . htmlspecialchars($product->get('pro_name')) . '". '
						. '<a href="/profile#orders">See it in your purchases</a>.');
				}
				throw new ShoppingCartException(
					'"' . htmlspecialchars($product->get('pro_name')) . '" is already owned by '
					. htmlspecialchars($added_for) . ', and it can only be owned once.');
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

			if ($product_group->get('prg_max_items') && $current_count >= $product_group->get('prg_max_items')) {
				throw new ShoppingCartException($product_group->get('prg_error'));
			}
		}

		
		$price = $product->get_price($product_version, $form_data);


		//HANDLE COUPONS
		$discount = $product->total_coupon_discount($price, $product_version, $this->coupon_codes);

		// Include ProductVersion as 6th element for easy access in views
		$this->items[] = array(1,	$product,	$form_data, $price, $discount, $product_version);

		// Apply any pending marketing-campaign coupon captured via ?coupon= URL.
		// This lands the discount on the cart without the user having to type the code.
		self::apply_pending_coupon($this);

		// Record conversion event: visitor added an item to the shopping cart.
		require_once(PathHelper::getIncludePath('data/visitor_events_class.php'));
		$session->save_visitor_event(VisitorEvent::TYPE_CART_ADD);

		$this->persist();
	}

	public function add_coupon($coupon_code){
			$coupon_code_test = CouponCode::GetByColumn('ccd_code', trim(strtolower($coupon_code)));

			if($coupon_code_test){
				if($coupon_code_test->is_valid()){
					$this->coupon_codes[] = $coupon_code_test->get('ccd_code');
					$this->coupon_codes = array_unique($this->coupon_codes);
					$this->update_items_for_coupon();
					$this->persist();
					return 1;
				}
				else{
					return 'Coupon code not valid.';
				}
			}
			else{
				return 'Coupon code not found.';
			}
	}

	public function remove_coupon($coupon_code){
		unset($this->coupon_codes[array_search(trim($coupon_code), $this->coupon_codes)]);
		$this->update_items_for_coupon();

		//IF THERE ARE NONE LEFT, CLEAR THE BILLING USER
		if(count($this->coupon_codes) == 0){
			$this->billing_user = NULL;
		}
		$this->persist();
	}
	
	public function billing_user_prefill_from_items(){
		// Use the first cart item that carries a full email/name set —
		// previously this returned false on the first item every time.
		foreach($this->items as $key => $cart_item) {
			list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
			if(!empty($data['email'])){
				$this->billing_user['billing_first_name'] = isset($data['full_name_first']) ? $data['full_name_first'] : '';
				$this->billing_user['billing_last_name'] = isset($data['full_name_last']) ? $data['full_name_last'] : '';
				$this->billing_user['billing_email'] = strtolower(trim($data['email']));
				$this->persist();
				return true;
			}
		}
		return false;
	}
	
	public function is_billing_user_complete(){
		// Guests and logged-in users share the same gate now: name + email.
		// Password is auto-generated by User::CreateNew when omitted, and
		// terms consent is communicated via implicit-consent copy on the
		// action button rather than a separate checkbox.
		if(is_array($this->billing_user)
			&& !empty($this->billing_user['billing_first_name'])
			&& !empty($this->billing_user['billing_last_name'])
			&& !empty($this->billing_user['billing_email'])){
			return 1;
		}
		return 0;
	}
	
	public function determine_billing_user($data, $clear_first){
		if($clear_first){
			$this->billing_user = NULL;
		}
		
		if(!empty($data['billing_email'])){
				$this->billing_user['billing_first_name'] = $data['billing_first_name'];
				$this->billing_user['billing_last_name'] = $data['billing_last_name'];
				$this->billing_user['billing_email'] = strtolower(trim($data['billing_email']));
		}
		else if(!empty($data['existing_billing_email'])){
			//IF THE USER TYPED IN A NEW BILLING USER
			if($data['existing_billing_email'] == 'A different person'){
				$this->billing_user['billing_first_name'] = $data['billing_first_name'];
				$this->billing_user['billing_last_name'] = $data['billing_last_name'];
				$this->billing_user['billing_email'] = strtolower(trim($data['billing_email']));
			}
		}

		if(!$this->is_billing_user_complete()){
			$this->billing_user_prefill_from_items();
		}

		$this->persist();
	}

	public function update_items_for_coupon(){

		foreach($this->items as $key => $cart_item) {
			list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
			$price = $product->get_price($product_version, $data);

			$discount = $product->total_coupon_discount($price, $product_version, $this->coupon_codes);

			$this->items[$key][4] = $discount;
		}
		$this->persist();
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
			list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
			$item_array[] = array($key, $quantity, (array)$product, (array)$data);
		}
		return $item_array;
	}	

	public function get_detailed_items() {
		$detailed_items = array();
		foreach ($this->items as $key => $cart_item) {
			list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
			$name = $product->get('pro_name') . ' - ' . $product_version->get('prv_version_name');
			
			$detailed_items[] = array(
				'id' => $key,
				'name' => $name,
				'price' => $price,
				'discount' => $discount,
				'quantity' => $quantity,
				'total' => $quantity * $price,
				'recurring' => $product_version->is_subscription(),
				'trial_period_days' => $product_version->get('prv_trial_period_days'),
				'product_version' => $product_version,
			);
		}
		return $detailed_items;
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
				$this->persist();
				return TRUE;
			}
		}
		return FALSE;
	}

	public function update_item($item_index, $form_data) {
		if (!isset($this->items[$item_index])) {
			return FALSE;
		}
		list($quantity, $product, $old_data, $price, $discount, $product_version) = $this->items[$item_index];

		$new_product_version = $product->get_product_versions(TRUE, $form_data['product_version']);
		$price = $product->get_price($new_product_version, $form_data);
		$discount = $product->total_coupon_discount($price, $new_product_version, $this->coupon_codes);

		$this->items[$item_index] = array($quantity, $product, $form_data, $price, $discount, $new_product_version);
		$this->persist();
		return TRUE;
	}

	public function get_item($item_index) {
		if (!isset($this->items[$item_index])) {
			return NULL;
		}
		return $this->items[$item_index];
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
		$this->coupon_codes = array();
		$this->billing_user = array();
		// Allow a fresh CHECKOUT_START event when the visitor starts a new cart
		unset($_SESSION['checkout_started']);
		$this->persist();
	}

}

?>
