<?php
/** @joinery-test
 * name: card_checkout_failures
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */

/**
 * The card checkout (stripe_regular) when Stripe says no, with Stripe doubled.
 *
 * specs/easy_signup_completion.md WP1a:
 *   B150  a card refused when it is saved reaches the buyer with the bank's
 *         reason, and the order records it.
 *   B126  a subscription Stripe makes 'incomplete' (its first invoice was
 *         declined) is not fulfilled: the buyer is refused before any
 *         one-time line is charged, and the subscription is cancelled.
 *   B128  a one-time charge refused after a subscription was made takes the
 *         subscription back: cancelled, its first invoice refunded.
 * And the paths that work stay working: a subscription plus a one-time line,
 * and a subscription alone.
 *
 * Stripe is a subclass of StripeHelper whose network calls are answered here,
 * stood in through _cart_charge_gateway(). Nothing reaches Stripe.
 *
 * Run: php plugins/store/tests/card_checkout_failures_test.php
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

$run_id = substr(md5(uniqid('ccf', true)), 0, 8);

/** One Stripe API service (subscriptions, refunds): records calls, answers from a script. */
class CcfFakeService {
	public $calls = array();
	public $answers = array();
	public function __call($method, $args) {
		$this->calls[] = array($method, $args);
		if (!isset($this->answers[$method])) { return null; }
		return call_user_func_array($this->answers[$method], $args);
	}
}

class CcfFakeClient {
	public $subscriptions;
	public $refunds;
	public function __construct() {
		$this->subscriptions = new CcfFakeService();
		$this->refunds = new CcfFakeService();
	}
}

/** StripeHelper with every network call answered here. */
class CcfStripe extends StripeHelper {
	public $client;
	public $log = array();
	public $refuse_card = null;
	public $refuse_charge = null;
	/** Subscriptions Stripe "makes", in order: status, amount paid, intent status, decline. */
	public $subscription_plan = array();

	public function __construct() {
		$this->test_mode = true;
		$this->client = new CcfFakeClient();
		$prop = new ReflectionProperty('StripeHelper', 'stripe');
		$prop->setValue($this, $this->client);
		$helper = $this;
		$this->client->subscriptions->answers['create'] = function ($params) use ($helper) {
			$helper->log[] = 'subscription';
			$plan = array_shift($helper->subscription_plan) ?: array('active', 1299, 'succeeded', null);
			$n = count($helper->log);
			return \Stripe\Subscription::constructFrom(array(
				'id' => 'sub_ccf_' . $n, 'object' => 'subscription', 'status' => $plan[0],
				'latest_invoice' => array(
					'id' => 'in_ccf_' . $n, 'object' => 'invoice', 'amount_paid' => $plan[1],
					'payment_intent' => array(
						'id' => 'pi_ccf_' . $n, 'object' => 'payment_intent', 'status' => $plan[2],
						'last_payment_error' => $plan[3] ? array('message' => $plan[3]) : null,
					),
				),
			));
		};
	}
	public function get_or_create_stripe_customer($user) { return 'cus_ccf'; }
	public function get_or_create_price($product_version, $price = NULL) { return array('id' => 'price_ccf_' . $product_version->key); }
	public function create_card_from_token($token, $customer, $set_as_default = true) {
		$this->log[] = 'card';
		if ($this->refuse_card) { throw new SystemDisplayableError('Your card was not accepted. ' . $this->refuse_card); }
		return array('id' => 'card_ccf');
	}
	public function process_charge($source, $amount, $customer, $item_list, $billing_user, $order = NULL) {
		$this->log[] = 'charge';
		if ($this->refuse_charge) { throw new SystemDisplayableError($this->refuse_charge); }
		return (object)array('id' => 'ch_ccf');
	}
}

// ---------------------------------------------------------------------------
// Fixtures: a one-time product and a monthly one, made through the admin logic
// ---------------------------------------------------------------------------

$admin = make_user('CcfAdmin' . $run_id, 10);
$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = (int)$admin->key;
$_SESSION['permission'] = 10;
$_SESSION['test_mode'] = true;
$_SESSION['send_emails'] = false;

// Adding to the cart asks Stripe for a price when a Stripe checkout is on;
// the cart is filled with checkout off and the charge runs with it on.
harness_set_setting_mem('checkout_type', 'none');
harness_set_setting_mem('use_paypal_checkout', '0');
harness_set_setting_mem('products_active', '1');

function ccf_product($name, $price, $price_type) {
	$result = harness_call_logic_ok('plugins/store/admin/logic/admin_product_edit_logic.php', 'admin_product_edit_logic', array(
		'action' => 'add', 'json_confirm' => '1', 'pro_name' => $name,
		'pro_description' => 'card_checkout_failures fixture', 'pro_short_description' => 'fixture',
		'pro_is_active' => '1', 'pro_max_purchase_count' => '0', 'pro_max_cart_count' => '0', 'pro_expires' => '0',
		'pro_link' => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)),
		'system_requirements' => array('FullNameRequirement', 'EmailRequirement'),
	));
	if (!$result->redirect || !preg_match('/pro_product_id=(\d+)/', $result->redirect, $m)) { return null; }
	$id = (int)$m[1];
	harness_defer(function () use ($id) {
		try { $p = new Product($id, TRUE); if ($p->key) $p->permanent_delete(); }
		catch (\Throwable $e) { echo "  WARNING: product $id not deleted: " . $e->getMessage() . "\n"; }
	});
	harness_call_logic_ok('plugins/store/admin/logic/admin_product_edit_logic.php', 'admin_product_edit_logic', array(
		'action' => 'new_version', 'p' => $id, 'version_name' => 'V', 'version_price' => $price,
		'prv_price_type' => $price_type, 'prv_trial_period_days' => '0',
	), 'POST');
	return $id;
}

$one_time_id = ccf_product('CCF Domain ' . $run_id, '20.00', 'single');
$monthly_id = ccf_product('CCF Hosting ' . $run_id, '12.99', 'month');
check($one_time_id && $monthly_id, 'a one-time and a monthly product are made');

/** Fill a fresh cart for a fresh buyer. */
function ccf_cart(array $product_ids, $label) {
	$cart = ShoppingCart::current();
	$cart->clear_cart();
	$email = harness_fixture_email('ccf_' . $label . '_' . $GLOBALS['run_id']);
	foreach ($product_ids as $product_id) {
		$product = new Product($product_id, TRUE);
		$versions = $product->get_product_versions(TRUE);
		$post = array('product_id' => $product_id, 'product_version' => $versions->get(0)->key, 'cart' => '1',
			'full_name_first' => 'Card', 'full_name_last' => 'Tester', 'email' => $email);
		$original_post = $_POST; $original_request = $_REQUEST;
		$_POST = $post; $_REQUEST = $post;
		try { product_logic($post); }
		finally { $_POST = $original_post; $_REQUEST = $original_request; }
	}
	ShoppingCart::current()->billing_user_prefill_from_items();
	return $email;
}

/** Run the card checkout against the double; returns [redirect, the order made, its messages]. */
function ccf_charge(CcfStripe $stripe) {
	_cart_charge_gateway('StripeHelper', $stripe);
	harness_set_setting_mem('checkout_type', 'stripe_regular');
	$db = DbConnector::get_instance()->get_db_link();
	$watermark = (int)$db->query('SELECT COALESCE(MAX(ord_order_id), 0) FROM ord_orders')->fetchColumn();
	unset($_SESSION['saved_messages']);

	$post = array('stripeToken' => 'tok_ccf', 'password' => '');
	$original = array($_POST, $_REQUEST, $_GET, $_SERVER['REQUEST_METHOD'] ?? null);
	$_POST = $post; $_REQUEST = $post; $_GET = array();
	$_SERVER['REQUEST_METHOD'] = 'POST';
	try { $result = cart_charge_logic($post); }
	finally {
		list($_POST, $_REQUEST, $_GET, $method) = $original;
		if ($method === null) unset($_SERVER['REQUEST_METHOD']); else $_SERVER['REQUEST_METHOD'] = $method;
		harness_set_setting_mem('checkout_type', 'none');
	}

	$q = $db->prepare('SELECT ord_order_id FROM ord_orders WHERE ord_order_id > ? ORDER BY ord_order_id DESC LIMIT 1');
	$q->execute(array($watermark));
	$order_id = $q->fetchColumn();
	$order = null;
	if ($order_id) {
		$order = new Order((int)$order_id, TRUE);
		harness_register_row('ord_orders', 'ord_order_id', $order->key);
		foreach (new MultiOrderItem(array('odi_ord_order_id' => $order->key)) as $item) {
			harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);
		}
		$user = new User((int)$order->get('ord_usr_user_id'), TRUE);
		if ($user->key) harness_register_user($user);
	}
	$messages = array();
	foreach ((array)($_SESSION['saved_messages'] ?? array()) as $m) {
		$messages[] = is_object($m) ? (string)($m->message_text ?? $m->message ?? json_encode((array)$m)) : (string)$m;
	}
	return array($result->redirect, $order, implode(' | ', $messages));
}

function ccf_items($order) {
	$items = array();
	if ($order) { foreach (new MultiOrderItem(array('odi_ord_order_id' => $order->key)) as $item) { $items[] = $item; } }
	return $items;
}

function ccf_calls(CcfFakeService $service, $method) {
	$found = array();
	foreach ($service->calls as $call) { if ($call[0] === $method) $found[] = $call[1]; }
	return $found;
}

// ===========================================================================
section('B150: a card refused when it is saved');
// ===========================================================================
ccf_cart(array($one_time_id), 'rc');
$stripe = new CcfStripe();
$stripe->refuse_card = 'Your card was declined.';
list($redirect, $order, $messages) = ccf_charge($stripe);
check($redirect === '/checkout', 'the buyer is sent back to checkout', var_export($redirect, true));
check(strpos($messages, 'Your card was declined.') !== false, 'with the bank\'s reason', $messages);
check($order && (int)$order->get('ord_status') === Order::STATUS_ERROR, 'the order records the refusal',
	$order ? 'status ' . $order->get('ord_status') : 'no order');
check($order && strpos((string)$order->get('ord_error'), 'declined') !== false, 'and why', $order ? (string)$order->get('ord_error') : '');
check(!in_array('charge', $stripe->log, true), 'nothing is charged');

// ===========================================================================
section('B126: a subscription whose first payment is declined');
// ===========================================================================
ccf_cart(array($monthly_id, $one_time_id), 'inc');
$stripe = new CcfStripe();
$stripe->subscription_plan = array(array('incomplete', 0, 'requires_payment_method', 'Your card has insufficient funds.'));
list($redirect, $order, $messages) = ccf_charge($stripe);
check($redirect === '/checkout', 'the buyer is sent back to checkout', var_export($redirect, true));
check(strpos($messages, 'insufficient funds') !== false, 'told why the first payment failed', $messages);
check(!in_array('charge', $stripe->log, true), 'the one-time line is not charged', implode(',', $stripe->log));
check(count(ccf_calls($stripe->client->subscriptions, 'cancel')) === 1, 'the incomplete subscription is cancelled');
check(count(ccf_calls($stripe->client->refunds, 'create')) === 0, 'nothing to refund: nothing was paid');
check($order && (int)$order->get('ord_status') === Order::STATUS_ERROR, 'the order records the refusal');
check(count(ccf_items($order)) === 0, 'no line is made, so nothing is fulfilled');

ccf_cart(array($monthly_id), 'ra');
$stripe = new CcfStripe();
$stripe->subscription_plan = array(array('incomplete', 0, 'requires_action', null));
list($redirect, $order, $messages) = ccf_charge($stripe);
check($redirect === '/checkout' && strpos($messages, 'bank asked to confirm') !== false,
	'a bank asking for 3-D Secure is told plainly', $messages);

// ===========================================================================
section('B128: the one-time charge refused after the subscription was made');
// ===========================================================================
ccf_cart(array($monthly_id, $one_time_id), 'cr');
$stripe = new CcfStripe();
$stripe->refuse_charge = 'Your card was declined.';
list($redirect, $order, $messages) = ccf_charge($stripe);
check($stripe->log === array('card', 'subscription', 'charge'), 'the subscription is made before the charge', implode(',', $stripe->log));
check($redirect === '/checkout', 'the buyer is sent back to checkout', var_export($redirect, true));
$cancels = ccf_calls($stripe->client->subscriptions, 'cancel');
$refunds = ccf_calls($stripe->client->refunds, 'create');
check(count($cancels) === 1 && $cancels[0][0] === 'sub_ccf_2', 'the subscription is cancelled', json_encode($cancels));
check(count($refunds) === 1 && ($refunds[0][0]['payment_intent'] ?? '') === 'pi_ccf_2', 'and its first payment refunded', json_encode($refunds));
check($order && (int)$order->get('ord_status') === Order::STATUS_ERROR, 'the order records the refusal');
check(count(ccf_items($order)) === 0, 'no line is made');

ccf_cart(array($monthly_id, $one_time_id), 'uf');
$stripe = new CcfStripe();
$stripe->refuse_charge = 'Your card was declined.';
$stripe->client->subscriptions->answers['cancel'] = function ($id) { throw new \Stripe\Exception\ApiConnectionException('Stripe unreachable'); };
list($redirect, $order, $messages) = ccf_charge($stripe);
check($redirect === '/checkout', 'a take-back that fails still answers the buyer');
check($order && strpos((string)$order->get('ord_error'), 'NOT UNDONE') !== false, 'and the order says what is left for the operator',
	$order ? (string)$order->get('ord_error') : '');

// ===========================================================================
section('What works keeps working');
// ===========================================================================
ccf_cart(array($monthly_id, $one_time_id), 'mx');
$stripe = new CcfStripe();
list($redirect, $order, $messages) = ccf_charge($stripe);
check($redirect !== '/checkout', 'a subscription plus a one-time line completes', $messages);
check($order && (int)$order->get('ord_status') === Order::STATUS_PAID, 'the order is paid');
$items = ccf_items($order);
$sub_item = null;
foreach ($items as $item) { if ($item->get('odi_is_subscription')) $sub_item = $item; }
check(count($items) === 2, 'with both lines', count($items) . ' lines');
check($sub_item && $sub_item->get('odi_stripe_subscription_id') === 'sub_ccf_2', 'the subscription line carries its subscription');
check($sub_item && $sub_item->get('odi_stripe_foreign_invoice_id') === 'in_ccf_2', 'and its first invoice');
check($sub_item && $sub_item->get('odi_subscription_status') === 'active' && (int)$sub_item->get('odi_status') === OrderItem::STATUS_PAID,
	'and is live and paid');
check(count(ccf_calls($stripe->client->subscriptions, 'cancel')) === 0, 'nothing is cancelled');

ccf_cart(array($monthly_id), 'so');
$stripe = new CcfStripe();
list($redirect, $order, $messages) = ccf_charge($stripe);
check($redirect !== '/checkout', 'a subscription alone completes', $messages);
check(!in_array('charge', $stripe->log, true), 'with no one-time charge');
check($order && (int)$order->get('ord_status') === Order::STATUS_PAID, 'and its order is paid');

ShoppingCart::current()->clear_cart();
harness_finish();
