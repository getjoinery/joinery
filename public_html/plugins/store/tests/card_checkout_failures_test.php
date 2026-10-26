<?php
/** @joinery-test
 * name: card_checkout_failures
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */

/**
 * The card checkout (stripe_regular), with Stripe doubled at its API.
 *
 * specs/easy_signup_completion.md WP1a and WP1c. The card form posts a
 * PaymentMethod; the one-time lines are authorized, the subscription made,
 * and the authorization captured last. Covered:
 *   - the paths that work: one-time, subscription, both, a token posted by
 *     an old page;
 *   - 3-D Secure (B133): the bank asks, the buyer goes back to /checkout and
 *     the checkout carries on, or the bank says no and everything made is
 *     taken back; a cart changed meanwhile is refused;
 *   - refusals (B150, B126, B128, R3, R4, R9): a card refused when saved,
 *     Stripe out of reach, a subscription declined, a capture declined after
 *     the subscription was made, a take-back that fails, a price that cannot
 *     be made;
 *   - one subscription per card checkout.
 *
 * StripeHelper runs as written; only its Stripe client is a double (and the
 * customer and price lookups, which list Stripe's catalogue). Nothing
 * reaches Stripe.
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

function ccf_card_error($message) {
	return \Stripe\Exception\CardException::factory($message, 402, null,
		array('error' => array('type' => 'card_error', 'message' => $message, 'decline_code' => 'generic_decline')));
}

/** One Stripe API service: records each call in the shared log, answers from a script. */
class CcfFakeService {
	public $name;
	public $log;
	public $answers = array();
	public function __construct($name, &$log) { $this->name = $name; $this->log = &$log; }
	public function __call($method, $args) {
		$this->log[] = $this->name . '.' . $method;
		if (!isset($this->answers[$method])) { return null; }
		return call_user_func_array($this->answers[$method], $args);
	}
	public function calls($method) {
		return count(array_keys($this->log, $this->name . '.' . $method));
	}
}

class CcfFakeClient {
	public $log = array();
	public $paymentMethods, $customers, $paymentIntents, $subscriptions, $refunds;
	public function __construct() {
		foreach (array('paymentMethods', 'customers', 'paymentIntents', 'subscriptions', 'refunds') as $name) {
			$this->$name = new CcfFakeService($name, $this->log);
		}
	}
}

/** StripeHelper as written, on the double. */
class CcfStripe extends StripeHelper {
	public $client;
	public $price_error = null;
	/** The PaymentIntent's status at create, and at each later retrieve. */
	public $intent_status = 'requires_capture';
	public $intent_status_later = 'requires_capture';
	public $intent_decline = null;
	public $capture_error = null;
	/** The subscription at create: status, amount paid, its first invoice's intent status, decline. */
	public $subscription = array('active', 1299, 'succeeded', null);
	public $subscription_later = null;
	public $last_create = array();

	public function __construct() {
		$this->test_mode = true;
		$this->client = new CcfFakeClient();
		(new ReflectionProperty('StripeHelper', 'stripe'))->setValue($this, $this->client);
		// The form prints the publishable key; a made-up one in the test shape.
		(new ReflectionProperty('StripeHelper', 'api_key'))->setValue($this, 'pk_test_ccf');
		$h = $this;
		$this->client->paymentMethods->answers['create'] = function ($params) { return (object)array('id' => 'pm_from_token'); };
		$this->client->paymentIntents->answers['create'] = function ($params) use ($h) {
			$h->last_create['intent'] = $params;
			if ($h->intent_decline) { throw ccf_card_error($h->intent_decline); }
			return $h->intent($h->intent_status, $params['amount']);
		};
		$this->client->paymentIntents->answers['retrieve'] = function ($id) use ($h) {
			return $h->intent($h->intent_status_later, 2000);
		};
		$this->client->paymentIntents->answers['capture'] = function ($id) use ($h) {
			if ($h->capture_error) { throw ccf_card_error($h->capture_error); }
			return $h->intent('succeeded', 2000);
		};
		$this->client->subscriptions->answers['create'] = function ($params) use ($h) {
			$h->last_create['subscription'] = $params;
			return $h->sub($h->subscription);
		};
		$this->client->subscriptions->answers['retrieve'] = function ($id) use ($h) {
			return $h->sub($h->subscription_later ?: $h->subscription);
		};
	}

	public function intent($status, $amount) {
		return \Stripe\PaymentIntent::constructFrom(array(
			'id' => 'pi_ccf', 'object' => 'payment_intent', 'status' => $status, 'client_secret' => 'pi_ccf_secret',
			'amount_received' => $status === 'succeeded' ? $amount : 0,
			'latest_charge' => $status === 'succeeded' ? 'ch_ccf' : null,
			'last_payment_error' => $status === 'requires_payment_method' ? array('message' => 'Your bank declined the confirmation.') : null,
		));
	}

	public function sub(array $plan) {
		return \Stripe\Subscription::constructFrom(array(
			'id' => 'sub_ccf', 'object' => 'subscription', 'status' => $plan[0], 'current_period_end' => 1893456000,
			'latest_invoice' => array(
				'id' => 'in_ccf', 'object' => 'invoice', 'amount_paid' => $plan[1],
				'payment_intent' => array(
					'id' => 'pi_sub', 'object' => 'payment_intent', 'status' => $plan[2], 'client_secret' => 'pi_sub_secret',
					'last_payment_error' => $plan[3] ? array('message' => $plan[3]) : null,
				),
			),
		));
	}

	public function get_or_create_stripe_customer($user) { return 'cus_ccf'; }
	public function get_or_create_price($product_version, $price = NULL) {
		if ($this->price_error) { throw new SystemDisplayablePermanentError($this->price_error); }
		return array('id' => 'price_ccf_' . $product_version->key);
	}
	public function log() { return implode(',', $this->client->log); }
}

// ---------------------------------------------------------------------------
// Fixtures: a one-time product and two monthly ones, made through the admin logic
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
harness_set_setting_mem('site_currency', 'usd');

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
$monthly2_id = ccf_product('CCF Extra ' . $run_id, '5.00', 'month');
check($one_time_id && $monthly_id && $monthly2_id, 'a one-time and two monthly products are made');

/** Fill a fresh cart for a fresh buyer. */
function ccf_cart(array $product_ids, $label) {
	$cart = ShoppingCart::current();
	$cart->clear_cart();
	unset($_SESSION[StripeHelper::CARD_PENDING_KEY]);
	$email = harness_fixture_email('ccf_' . $label . '_' . $GLOBALS['run_id']);
	foreach ($product_ids as $product_id) {
		ccf_add($product_id, $email);
	}
	ShoppingCart::current()->billing_user_prefill_from_items();
	return $email;
}

function ccf_add($product_id, $email) {
	$product = new Product($product_id, TRUE);
	$versions = $product->get_product_versions(TRUE);
	$post = array('product_id' => $product_id, 'product_version' => $versions->get(0)->key, 'cart' => '1',
		'full_name_first' => 'Card', 'full_name_last' => 'Tester', 'email' => $email);
	$original_post = $_POST; $original_request = $_REQUEST;
	$_POST = $post; $_REQUEST = $post;
	try { product_logic($post); }
	finally { $_POST = $original_post; $_REQUEST = $original_request; }
}

function ccf_watermark() {
	return (int)DbConnector::get_instance()->get_db_link()->query('SELECT COALESCE(MAX(ord_order_id), 0) FROM ord_orders')->fetchColumn();
}

/**
 * Post to /cart_charge against the double: the card form's post by default,
 * or the bank-confirmation post. Returns [redirect, the newest order after
 * $watermark, its messages].
 */
function ccf_charge(CcfStripe $stripe, $watermark, array $post = array('stripePaymentMethod' => 'pm_ccf', 'password' => '')) {
	_cart_charge_gateway('StripeHelper', $stripe);
	harness_set_setting_mem('checkout_type', 'stripe_regular');
	unset($_SESSION['saved_messages']);

	$original = array($_POST, $_REQUEST, $_GET, $_SERVER['REQUEST_METHOD'] ?? null);
	$_POST = $post; $_REQUEST = $post; $_GET = array();
	$_SERVER['REQUEST_METHOD'] = 'POST';
	try { $result = cart_charge_logic($post); }
	finally {
		list($_POST, $_REQUEST, $_GET, $method) = $original;
		if ($method === null) unset($_SERVER['REQUEST_METHOD']); else $_SERVER['REQUEST_METHOD'] = $method;
		harness_set_setting_mem('checkout_type', 'none');
	}

	$db = DbConnector::get_instance()->get_db_link();
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

function ccf_confirm(CcfStripe $stripe, $watermark) {
	return ccf_charge($stripe, $watermark, array('card_payment_confirm' => '1'));
}

function ccf_items($order) {
	$items = array();
	if ($order) { foreach (new MultiOrderItem(array('odi_ord_order_id' => $order->key)) as $item) { $items[] = $item; } }
	return $items;
}

function ccf_status($order) {
	if (!$order) return -1;
	$order->load();
	return (int)$order->get('ord_status');
}

// ===========================================================================
section('What works');
// ===========================================================================
ccf_cart(array($one_time_id), 'ot');
$w = ccf_watermark();
$stripe = new CcfStripe();
list($redirect, $order, $messages) = ccf_charge($stripe, $w);
check($redirect !== '/checkout', 'a one-time cart completes', $messages);
check($stripe->log() === 'paymentMethods.attach,customers.update,paymentIntents.create,paymentIntents.capture',
	'the card is saved, the payment authorized, then captured', $stripe->log());
check(($stripe->last_create['intent']['capture_method'] ?? '') === 'manual' && empty($stripe->last_create['intent']['setup_future_usage']),
	'authorized for capture later, not saved for later use with no subscription to follow');
check(ccf_status($order) === Order::STATUS_PAID && $order->get('ord_stripe_payment_intent_id') === 'pi_ccf'
	&& $order->get('ord_stripe_charge_id') === 'ch_ccf', 'the order is paid and names its payment and charge');
check(count(ccf_items($order)) === 1, 'with its line');

ccf_cart(array($monthly_id, $one_time_id), 'mx');
$w = ccf_watermark();
$stripe = new CcfStripe();
list($redirect, $order, $messages) = ccf_charge($stripe, $w);
check($redirect !== '/checkout', 'a subscription plus a one-time line completes', $messages);
check(strpos($stripe->log(), 'paymentIntents.create,subscriptions.create,paymentIntents.capture') !== false,
	'authorized, subscribed, then captured last', $stripe->log());
check(($stripe->last_create['intent']['setup_future_usage'] ?? '') === 'off_session'
	&& !empty($stripe->last_create['subscription']['off_session']),
	'the authorization covers the subscription, which is charged off-session (one bank check, not two)');
$items = ccf_items($order);
$sub_item = null;
foreach ($items as $item) { if ($item->get('odi_is_subscription')) $sub_item = $item; }
check(ccf_status($order) === Order::STATUS_PAID && count($items) === 2, 'the order is paid with both lines');
check($sub_item && $sub_item->get('odi_stripe_subscription_id') === 'sub_ccf' && $sub_item->get('odi_stripe_foreign_invoice_id') === 'in_ccf'
	&& $sub_item->get('odi_subscription_status') === 'active', 'the subscription line carries its subscription and first invoice');
check($sub_item && strpos((string)$sub_item->get('odi_subscription_period_end'), '2030-01-01') === 0,
	'with its period end recorded at checkout (R2)');

ccf_cart(array($monthly_id), 'so');
$w = ccf_watermark();
$stripe = new CcfStripe();
list($redirect, $order, $messages) = ccf_charge($stripe, $w);
check($redirect !== '/checkout' && ccf_status($order) === Order::STATUS_PAID, 'a subscription alone completes and is paid', $messages);
check($stripe->client->paymentIntents->calls('create') === 0 && empty($stripe->last_create['subscription']['off_session']),
	'with no separate payment, and on-session');

ccf_cart(array($one_time_id), 'tk');
$w = ccf_watermark();
$stripe = new CcfStripe();
list($redirect, $order, $messages) = ccf_charge($stripe, $w, array('stripeToken' => 'tok_visa', 'password' => ''));
check($redirect !== '/checkout' && $stripe->client->paymentMethods->calls('create') === 1,
	'a token from a page loaded before the change is made into a payment method', $messages);

// ===========================================================================
section('B133: 3-D Secure');
// ===========================================================================
ccf_cart(array($monthly_id, $one_time_id), 'ds');
$w = ccf_watermark();
$stripe = new CcfStripe();
$stripe->intent_status = 'requires_action';
list($redirect, $order, $messages) = ccf_charge($stripe, $w);
$pending = $_SESSION[StripeHelper::CARD_PENDING_KEY] ?? null;
check($redirect === '/checkout' && ($pending['client_secret'] ?? '') === 'pi_ccf_secret',
	'when the bank asks, the buyer goes back to checkout with the confirmation to run', json_encode($pending));
check($stripe->client->subscriptions->calls('create') === 0 && $stripe->client->paymentIntents->calls('capture') === 0,
	'nothing further is made or taken yet');
check(ccf_status($order) === Order::STATUS_UNPAID, 'the order waits, unpaid');
$form = $stripe->output_stripe_regular_form(null);
check(strpos($form, '"pi_ccf_secret"') !== false && strpos($form, 'confirmCardPayment') !== false,
	'the checkout page runs the bank\'s confirmation');

$stripe->intent_status_later = 'requires_capture';
list($redirect, $order2, $messages) = ccf_confirm($stripe, $w);
check($redirect !== '/checkout' && $order2 && (int)$order2->key === (int)$order->key, 'once the bank confirms, the same order carries on', $messages);
check(ccf_status($order2) === Order::STATUS_PAID && count(ccf_items($order2)) === 2, 'and completes, paid, with both lines');
check($stripe->client->subscriptions->calls('create') === 1 && $stripe->client->paymentIntents->calls('capture') === 1,
	'the subscription is made and the payment captured once');
check(empty($_SESSION[StripeHelper::CARD_PENDING_KEY]), 'and nothing is left waiting');

ccf_cart(array($one_time_id), 'dn');
$w = ccf_watermark();
$stripe = new CcfStripe();
$stripe->intent_status = 'requires_action';
ccf_charge($stripe, $w);
$stripe->intent_status_later = 'requires_payment_method';
list($redirect, $order, $messages) = ccf_confirm($stripe, $w);
check($redirect === '/checkout' && strpos($messages, 'Your bank declined the confirmation.') !== false,
	'a bank that says no is a refusal with its reason', $messages);
check($stripe->client->paymentIntents->calls('cancel') === 1 && ccf_status($order) === Order::STATUS_ERROR,
	'the authorization is released and the order records it');
check(strpos($messages, 'Nothing was charged.') !== false, 'and the buyer is told nothing was charged');

ccf_cart(array($one_time_id), 'dl');
$w = ccf_watermark();
$stripe = new CcfStripe();
$stripe->intent_status = 'requires_action';
ccf_charge($stripe, $w);
$stripe->intent_status_later = 'requires_action';
list($redirect, $order, $messages) = ccf_confirm($stripe, $w);
check($redirect === '/checkout' && strpos($messages, 'did not confirm') !== false && ccf_status($order) === Order::STATUS_ERROR,
	'a confirmation that comes back unanswered is refused, not asked again', $messages);

ccf_cart(array($monthly_id), 'ds2');
$w = ccf_watermark();
$stripe = new CcfStripe();
$stripe->subscription = array('incomplete', 0, 'requires_action', null);
list($redirect, $order, $messages) = ccf_charge($stripe, $w);
check($redirect === '/checkout' && ($_SESSION[StripeHelper::CARD_PENDING_KEY]['client_secret'] ?? '') === 'pi_sub_secret',
	'a subscription\'s first payment the bank asks about goes back to checkout too');
$stripe->subscription_later = array('active', 1299, 'succeeded', null);
list($redirect, $order, $messages) = ccf_confirm($stripe, $w);
check($redirect !== '/checkout' && ccf_status($order) === Order::STATUS_PAID && $stripe->client->subscriptions->calls('create') === 1,
	'and completes with the same subscription once confirmed', $messages);

$email = ccf_cart(array($one_time_id), 'cc');
$w = ccf_watermark();
$stripe = new CcfStripe();
$stripe->intent_status = 'requires_action';
ccf_charge($stripe, $w);
ccf_add($monthly_id, $email);   // another tab adds to the cart while the bank is asking
$stripe->intent_status_later = 'requires_capture';
list($redirect, $order, $messages) = ccf_confirm($stripe, $w);
check($redirect === '/checkout' && strpos($messages, 'cart changed') !== false && $stripe->client->paymentIntents->calls('cancel') === 1
	&& $stripe->client->paymentIntents->calls('capture') === 0,
	'a cart changed meanwhile is refused and the authorization released', $messages);

// ===========================================================================
section('Refusals');
// ===========================================================================
ccf_cart(array($one_time_id), 'rc');
$w = ccf_watermark();
$stripe = new CcfStripe();
$stripe->client->paymentMethods->answers['attach'] = function () { throw ccf_card_error('Your card was declined.'); };
list($redirect, $order, $messages) = ccf_charge($stripe, $w);
check($redirect === '/checkout' && strpos($messages, 'Your card was declined.') !== false, 'a card refused when saved gives the bank\'s reason (B150)', $messages);
check(ccf_status($order) === Order::STATUS_ERROR && $stripe->client->paymentIntents->calls('create') === 0, 'records it, and charges nothing');

ccf_cart(array($one_time_id), 'ou');
$w = ccf_watermark();
$stripe = new CcfStripe();
$stripe->client->paymentMethods->answers['attach'] = function () { throw new \Stripe\Exception\ApiConnectionException('Could not connect to Stripe'); };
list($redirect, $order, $messages) = ccf_charge($stripe, $w);
check(strpos($messages, "couldn't reach our card processor") !== false && strpos($messages, 'not accepted') === false,
	'Stripe out of reach is not reported as a refused card (R3)', $messages);

ccf_cart(array($monthly_id, $one_time_id), 'inc');
$w = ccf_watermark();
$stripe = new CcfStripe();
$stripe->subscription = array('incomplete', 0, 'requires_payment_method', 'Your card has insufficient funds.');
list($redirect, $order, $messages) = ccf_charge($stripe, $w);
check($redirect === '/checkout' && strpos($messages, 'insufficient funds') !== false, 'a declined subscription is refused with why (B126)', $messages);
check($stripe->client->paymentIntents->calls('capture') === 0 && $stripe->client->paymentIntents->calls('cancel') === 1
	&& $stripe->client->subscriptions->calls('cancel') === 1, 'the one-time authorization is released and the subscription cancelled', $stripe->log());
check($stripe->client->refunds->calls('create') === 0 && strpos($messages, 'Nothing was charged.') !== false, 'nothing was charged, and the buyer is told so');
check(ccf_status($order) === Order::STATUS_ERROR && count(ccf_items($order)) === 0, 'the order records it, with no line to fulfil');

ccf_cart(array($monthly_id, $one_time_id), 'cr');
$w = ccf_watermark();
$stripe = new CcfStripe();
$stripe->capture_error = 'Your card was declined.';
list($redirect, $order, $messages) = ccf_charge($stripe, $w);
check($redirect === '/checkout' && $stripe->client->subscriptions->calls('cancel') === 1 && $stripe->client->refunds->calls('create') === 1,
	'a capture refused after the subscription was made cancels it and refunds its first payment (B128)', $stripe->log());
check(strpos($messages, '12.99 USD was taken and has been refunded') !== false, 'and tells the buyer (R4)', $messages);
check(ccf_status($order) === Order::STATUS_ERROR && count(ccf_items($order)) === 0, 'the order records it, with no line');

ccf_cart(array($monthly_id, $one_time_id), 'uf');
$w = ccf_watermark();
$stripe = new CcfStripe();
$stripe->capture_error = 'Your card was declined.';
$stripe->client->subscriptions->answers['cancel'] = function () { throw new \Stripe\Exception\ApiConnectionException('Stripe unreachable'); };
list($redirect, $order, $messages) = ccf_charge($stripe, $w);
check($redirect === '/checkout' && $order && strpos((string)$order->get('ord_error'), 'NOT UNDONE') !== false,
	'a take-back that fails says what is left for the operator', $order ? (string)$order->get('ord_error') : '');

ccf_cart(array($monthly_id), 'pe');
$w = ccf_watermark();
$stripe = new CcfStripe();
$stripe->price_error = 'This product does not have a stripe product id.';
list($redirect, $order, $messages) = ccf_charge($stripe, $w);
check($redirect === '/checkout' && ccf_status($order) === Order::STATUS_ERROR && strpos((string)$order->get('ord_error'), 'stripe product id') !== false,
	'a price that cannot be found refuses the checkout and records why (R9)', $messages);

ccf_cart(array($monthly_id, $monthly2_id), 'two');
$w = ccf_watermark();
$stripe = new CcfStripe();
list($redirect, $order, $messages) = ccf_charge($stripe, $w);
check($redirect === '/checkout' && strpos($messages, 'one subscription at a time') !== false && $stripe->log() === '',
	'two subscriptions in one card checkout are refused before Stripe is asked', $messages);
harness_set_setting_mem('checkout_type', 'stripe_regular');
check(ShoppingCart::current()->card_checkout_refusal() !== null, 'and the checkout page says so before offering the card form');
harness_set_setting_mem('checkout_type', 'none');

ShoppingCart::current()->clear_cart();
unset($_SESSION[StripeHelper::CARD_PENDING_KEY]);
harness_finish();
