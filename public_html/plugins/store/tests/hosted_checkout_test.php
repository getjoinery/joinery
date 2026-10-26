<?php
/** @joinery-test
 * name: hosted_checkout
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */

/**
 * The hosted payments (Stripe Checkout, PayPal): what a payment was for is a
 * GatewayPaymentRecord, written by the buyer's own POST, completed by the return
 * or by the webhook. specs/easy_signup_completion.md WP1d:
 *   - a checkout page view records nothing and makes nothing at Stripe;
 *   - the hosted page's button starts the session and records it;
 *   - the return completes the order, in the browser that paid or in another
 *     (B147), signing in only the one that paid;
 *   - the webhook completes it for a buyer who never comes back (B146), and
 *     the buyer's later return shows the receipt and empties the cart;
 *   - a refusal after payment keeps the order in error (B145);
 *   - the PayPal button records through store/checkout_record_paypal.
 *
 * Stripe is a stand-in through _cart_charge_gateway(); nothing reaches Stripe
 * or PayPal.
 *
 * Run: php plugins/store/tests/hosted_checkout_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();
harness_test_mode();

require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/ShoppingCart.php'));
require_once(PathHelper::getIncludePath('plugins/store/logic/product_logic.php'));
require_once(PathHelper::getIncludePath('plugins/store/logic/cart_charge_logic.php'));

$run_id = substr(md5(uniqid('hco', true)), 0, 8);

/** Stripe Checkout as a stand-in: sessions are made and read back here. */
class HcoStripe extends StripeHelper {
	public $made = array();
	public $paid_by = array();
	public function __construct() { $this->test_mode = true; }
	public function build_checkout_item_array($cart, $existing_billing_user) {
		return array('client_reference_id' => $existing_billing_user ? $existing_billing_user->key : null);
	}
	public function create_stripe_checkout_session($params) {
		$id = 'cs_test_hco_' . count($this->made) . '_' . $GLOBALS['run_id'];
		$this->made[$id] = $params;
		return (object)array('id' => $id, 'url' => 'https://checkout.stripe.test/' . $id);
	}
	/** The session as Stripe reports it once paid. */
	public function session($id) {
		return \Stripe\Checkout\Session::constructFrom(array(
			'id' => $id, 'object' => 'checkout.session', 'payment_status' => 'paid',
			'client_reference_id' => $this->made[$id]['client_reference_id'] ?? null,
			'payment_intent' => 'pi_' . $id, 'subscription' => null, 'amount_total' => 2000,
		));
	}
	public function validate_session_id($session_id) { return $this->session($session_id); }
	public function get_or_create_stripe_customer($user) { return 'cus_hco'; }
}

$admin = make_user('HcoAdmin' . $run_id, 10);
$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = (int)$admin->key;
$_SESSION['permission'] = 10;
$_SESSION['test_mode'] = true;
$_SESSION['send_emails'] = false;
harness_set_setting_mem('checkout_type', 'none');
harness_set_setting_mem('use_paypal_checkout', '0');
harness_set_setting_mem('products_active', '1');

$made = harness_call_logic_ok('plugins/store/admin/logic/admin_product_edit_logic.php', 'admin_product_edit_logic', array(
	'action' => 'add', 'json_confirm' => '1', 'pro_name' => 'HCO Widget ' . $run_id,
	'pro_description' => 'hosted_checkout fixture', 'pro_short_description' => 'fixture',
	'pro_is_active' => '1', 'pro_max_purchase_count' => '0', 'pro_max_cart_count' => '0', 'pro_expires' => '0',
	'pro_link' => 'hco-widget-' . $run_id, 'system_requirements' => array('FullNameRequirement', 'EmailRequirement'),
));
preg_match('/pro_product_id=(\d+)/', (string)$made->redirect, $m);
$product_id = (int)($m[1] ?? 0);
harness_defer(function () use ($product_id) {
	try { $p = new Product($product_id, TRUE); if ($p->key) $p->permanent_delete(); } catch (\Throwable $e) {}
});
harness_call_logic_ok('plugins/store/admin/logic/admin_product_edit_logic.php', 'admin_product_edit_logic', array(
	'action' => 'new_version', 'p' => $product_id, 'version_name' => 'V', 'version_price' => '20.00',
	'prv_price_type' => 'single', 'prv_trial_period_days' => '0',
), 'POST');
check($product_id > 0, 'a product is made');

harness_defer(function () use ($run_id) {
	$db = DbConnector::get_instance()->get_db_link();
	$db->prepare("DELETE FROM gpr_gateway_payment_records WHERE gpr_ref LIKE ?")->execute(array('cs_test_hco_%_' . $run_id));
	foreach ($GLOBALS['hco_paypal_refs'] ?? array() as $ref) {
		$db->prepare("DELETE FROM gpr_gateway_payment_records WHERE gpr_ref = ?")->execute(array($ref));
	}
});

/** A guest's fresh cart, in browser session $sid. */
function hco_cart($label, $sid) {
	hco_session($sid);
	unset($_SESSION['loggedin'], $_SESSION['usr_user_id'], $_SESSION['permission'], $_SESSION[ShoppingCart::SESSION_KEY]);
	harness_set_setting_mem('checkout_type', 'none');
	ShoppingCart::current()->clear_cart();
	$email = harness_fixture_email('hco_' . $label . '_' . $GLOBALS['run_id']);
	$product = new Product($GLOBALS['product_id'], TRUE);
	$post = array('product_id' => $product->key, 'product_version' => $product->get_product_versions(TRUE)->get(0)->key, 'cart' => '1',
		'full_name_first' => 'Hosted', 'full_name_last' => 'Buyer', 'email' => $email);
	$_POST = $post; $_REQUEST = $post; product_logic($post); $_POST = array(); $_REQUEST = array();
	ShoppingCart::current()->billing_user_prefill_from_items();
	harness_set_setting_mem('checkout_type', 'stripe_checkout');
	return $email;
}

/** Act as browser session $sid (the session id the record was started in, or another). */
function hco_session($sid) {
	GatewayPaymentRecord::$session_id_stand_in = $sid;
}

function hco_request($method, array $get, array $post) {
	unset($_SESSION['saved_messages']);
	$original = array($_POST, $_REQUEST, $_GET, $_SERVER['REQUEST_METHOD'] ?? null);
	$_GET = $get; $_POST = $post; $_REQUEST = array_merge($get, $post);
	$_SERVER['REQUEST_METHOD'] = $method;
	try { $result = cart_charge_logic(array_merge($get, $post)); }
	finally {
		list($_POST, $_REQUEST, $_GET, $m) = $original;
		if ($m === null) unset($_SERVER['REQUEST_METHOD']); else $_SERVER['REQUEST_METHOD'] = $m;
	}
	$messages = array();
	foreach ((array)($_SESSION['saved_messages'] ?? array()) as $msg) {
		$messages[] = is_object($msg) ? (string)($msg->message_text ?? $msg->message ?? '') : (string)$msg;
	}
	return array($result, implode(' | ', $messages));
}

function hco_start(HcoStripe $stripe) {
	_cart_charge_gateway('StripeHelper', $stripe);
	list($result, $messages) = hco_request('POST', array(), array('start_hosted_checkout' => '1', 'cart_cs' => ShoppingCart::current()->get_hash()));
	return array($result, $messages, array_key_last($stripe->made));
}

function hco_order($session_id) {
	$order = Order::GetByStripeSession($session_id);
	if ($order) {
		harness_register_row('ord_orders', 'ord_order_id', $order->key);
		foreach (new MultiOrderItem(array('odi_ord_order_id' => $order->key)) as $item) {
			harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);
		}
		if ($order->get('ord_usr_user_id')) {
			$u = new User((int)$order->get('ord_usr_user_id'), TRUE);
			if ($u->key) harness_register_user($u);
		}
	}
	return $order;
}

function hco_lines($order) {
	return $order ? count(iterator_to_array(new MultiOrderItem(array('odi_ord_order_id' => $order->key)))) : 0;
}

$db = DbConnector::get_instance()->get_db_link();
$records = function () use ($db) { return (int)$db->query('SELECT count(*) FROM gpr_gateway_payment_records')->fetchColumn(); };

// ===========================================================================
section('A page view records nothing; the button starts and records');
// ===========================================================================
hco_cart('pv', 'hcosessA' . $run_id);
$stripe = new HcoStripe();
_cart_charge_gateway('StripeHelper', $stripe);
$before = $records();
harness_call_logic('plugins/store/logic/checkout_logic.php', 'checkout_logic', array(), 'GET');
check($records() === $before && count($stripe->made) === 0, 'viewing the checkout page records nothing and makes nothing at Stripe');
check(strpos((new HcoStripe())->output_stripe_checkout_form('h'), 'start_hosted_checkout') !== false, 'its Pay button posts to start the payment');

list($result, $messages, $sid) = hco_start($stripe);
$row = GatewayPaymentRecord::GetByRef((string)$sid);
check($result->redirect === 'https://checkout.stripe.test/' . $sid, 'the button sends the buyer to Stripe\'s page', (string)$result->redirect . ' ' . $messages);
check($row && $row->get('gpr_state') === 'open' && $row->started_in_this_session(), 'and records the payment, for this browser session');

list($result, $messages) = hco_request('POST', array(), array('start_hosted_checkout' => '1', 'cart_cs' => 'stale'));
check($result->redirect === '/checkout' && strpos($messages, 'cart changed') !== false, 'a cart changed since the page was shown is shown again', $messages);

// ===========================================================================
section('The return, in the browser that paid');
// ===========================================================================
list($result, $messages) = hco_request('GET', array('session_id' => $sid), array());
$order = hco_order($sid);
check($order && (int)$order->get('ord_status') === Order::STATUS_PAID && hco_lines($order) === 1, 'completes the order with its line', $messages);
$row = GatewayPaymentRecord::GetByRef((string)$sid);
check($row->get('gpr_state') === 'completed' && (int)$row->get('gpr_ord_order_id') === (int)$order->key, 'the record is spent into it');
check(count(ShoppingCart::current()->items) === 0 && !empty(ShoppingCart::current()->last_receipt), 'the cart is emptied and holds the receipt');
check(!empty($_SESSION['usr_user_id']), 'the new buyer is signed in, in the browser that paid');
list($result, $messages) = hco_request('GET', array('session_id' => $sid), array());
check($result->redirect === '/cart_confirm' && hco_lines(hco_order($sid)) === 1, 'following the return again shows the receipt, no second order');

// ===========================================================================
section('B147: the return in another browser session');
// ===========================================================================
hco_cart('other', 'hcosessB' . $run_id);
list($result, $messages, $sid) = hco_start($stripe);
hco_session('hcosessOther' . $run_id);
unset($_SESSION['loggedin'], $_SESSION['usr_user_id']);
list($result, $messages) = hco_request('GET', array('session_id' => $sid), array());
$order = hco_order($sid);
check($order && (int)$order->get('ord_status') === Order::STATUS_PAID && hco_lines($order) === 1,
	'a return in another browser session completes the order', $messages);
check(empty($_SESSION['usr_user_id']), 'without signing that browser in');
check(!empty(ShoppingCart::current()->last_receipt) && count(ShoppingCart::current()->items) === 1,
	'that browser is shown the receipt, its cart left alone (R7)');

// ===========================================================================
section('B146: the webhook, for a buyer who never comes back');
// ===========================================================================
$email = hco_cart('wh', 'hcosessC' . $run_id);
list($result, $messages, $sid) = hco_start($stripe);
SystemBase::$allow_get_mutation = true;
hco_session('hcoWebhook' . $run_id);
$ran = Order::with_payment_lock($sid, function () use ($stripe, $sid) { return cart_charge_complete_from_webhook($stripe->session($sid)); });
SystemBase::$allow_get_mutation = false;
$order = hco_order($sid);
check($ran === null && $order && (int)$order->get('ord_status') === Order::STATUS_PAID && hco_lines($order) === 1,
	'the webhook completes the order with its line');
hco_session('hcosessC' . $run_id);
check(count(ShoppingCart::current()->items) === 1, 'the buyer\'s cart is not touched by the webhook');
list($result, $messages) = hco_request('GET', array('session_id' => $sid), array());
check($result->redirect === '/cart_confirm' && hco_lines(hco_order($sid)) === 1, 'the buyer\'s later return shows the receipt, no second order');
check(count(ShoppingCart::current()->items) === 0 && !empty(ShoppingCart::current()->last_receipt), 'and empties the cart of what was paid for');
check((int)($_SESSION['usr_user_id'] ?? 0) === (int)hco_order($sid)->get('ord_usr_user_id'),
	'and signs the guest into the account the checkout made for them (R1)');

// ===========================================================================
section('B145: a refusal after payment');
// ===========================================================================
$email = hco_cart('rf', 'hcosessD' . $run_id);
list($result, $messages, $sid) = hco_start($stripe);
$taken = make_user('HcoTaken' . $run_id);
$taken->set('usr_email', $email);
$taken->save();
list($result, $messages) = hco_request('GET', array('session_id' => $sid), array());
$order = hco_order($sid);
check($result->redirect === '/cart_confirm', 'a payment that cannot be completed lands where no second payment is offered', $messages);
check($order && (int)$order->get('ord_status') === Order::STATUS_ERROR && strpos((string)$order->get('ord_error'), 'Paid but not completed') === 0,
	'its order is kept, in error, with the reason', $order ? (string)$order->get('ord_error') : 'no order');
check(GatewayPaymentRecord::GetByRef((string)$sid)->get('gpr_state') === 'refused', 'and its record is marked refused');
$error_before = (string)$order->get('ord_error');
list($result, $messages) = hco_request('GET', array('session_id' => $sid), array());
$order->load();
check($result->redirect === '/cart_confirm' && strpos($messages, 'belongs to an account') !== false
	&& (string)$order->get('ord_error') === $error_before && hco_lines($order) === 0,
	'a reload shows the same reason, and changes nothing (R2)', $messages);

list($result, $messages) = hco_request('GET', array('session_id' => 'cs_test_unknown_' . $run_id), array());
check($result->redirect === '/cart_confirm', 'a paid session with no record lands on the confirmation page too');

// ===========================================================================
section('PayPal: the button records through its action');
// ===========================================================================
hco_cart('pp', 'hcosessE' . $run_id);
harness_set_setting_mem('use_paypal_checkout', '1');
harness_set_setting_mem('paypal_api_key_test', 'hco_fake_key');
harness_set_setting_mem('paypal_api_secret_test', 'hco_fake_secret');
$before = $records();
$stale = harness_call_logic('plugins/store/logic/checkout_record_paypal_logic.php', 'checkout_record_paypal_logic', array('cart_cs' => 'stale'), 'POST');
check($stale->error && strpos((string)$stale->error, 'cart changed') !== false && $records() === $before,
	'a page showing a changed cart is refused before PayPal is asked (R3)', (string)$stale->error);
$recorded = harness_call_logic('plugins/store/logic/checkout_record_paypal_logic.php', 'checkout_record_paypal_logic',
	array('cart_cs' => ShoppingCart::current()->get_hash()), 'POST');
$custom_id = (string)($recorded->data['custom_id'] ?? '');
check(strpos($custom_id, 'pp_') === 0 && $records() === $before + 1, 'the action records the cart and answers the reference', json_encode($recorded->data) . ' ' . (string)$recorded->error);
$pp = ShoppingCart::recorded_payment($custom_id);
check($pp && $pp['gateway'] === 'paypal' && count($pp['items']) === 1, 'the record holds the cart, for PayPal\'s return');
$GLOBALS['hco_paypal_refs'][] = $custom_id;

/** PayPal as a stand-in: the payment its return reads back. */
class HcoPaypal extends PaypalHelper {
	public $payment;
	public function validatePayment($id) { return $this->payment; }
}
$paypal = new HcoPaypal();
$paypal_id = 'PPHCO' . strtoupper($run_id);
$paypal->payment = array('status' => 'COMPLETED', 'purchase_units' => array(array(
	'custom_id' => $custom_id, 'amount' => array('value' => '1.00', 'currency_code' => 'USD'))));
_cart_charge_gateway('PaypalHelper', $paypal);
list($result, $messages) = hco_request('GET', array('id' => $paypal_id), array());
$pp_order = null;
foreach (new MultiOrder(array('ord_paypal_order_id' => $paypal_id)) as $o) { $pp_order = $o; harness_register_row('ord_orders', 'ord_order_id', $o->key); }
check($result->redirect === '/cart_confirm' && $pp_order && (int)$pp_order->get('ord_status') === Order::STATUS_ERROR
	&& strpos((string)$pp_order->get('ord_error'), 'amount paid') !== false,
	'a PayPal payment of the wrong amount is kept as an order in error, not lost (R3)', $pp_order ? (string)$pp_order->get('ord_error') : $messages);
harness_set_setting_mem('use_paypal_checkout', '0');

ShoppingCart::current()->clear_cart();
harness_finish();
