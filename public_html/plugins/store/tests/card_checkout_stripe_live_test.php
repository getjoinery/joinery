<?php
/** @joinery-test
 * name: card_checkout_stripe_live
 * tier: live
 * env: dev-only
 * needs: [stripe-test-keys]
 * timeout: 900
 */

/**
 * The card checkout (stripe_regular) against Stripe's test mode.
 *
 * card_checkout_failures proves the checkout's own logic with Stripe doubled;
 * this proves Stripe answers the way that logic expects. Each section posts
 * to cart_charge_logic as the card form does, with one of Stripe's test
 * payment methods, then reads back from Stripe what was made:
 *
 *   - a one-time cart: authorized, then captured; the order names the charge.
 *   - a subscription plus a one-time line: the authorization saved for later,
 *     the subscription charged off-session without asking the bank, the
 *     authorization captured; then each line refunded from its own payment,
 *     the subscription cancelled with it (WP1b on real Stripe).
 *   - a subscription alone.
 *   - declines: a card refused outright, a card that saves but is declined
 *     on the amount, insufficient funds on a subscription. Nothing is left
 *     charged or live at Stripe.
 *   - 3-D Secure: a card that always asks stops the checkout and keeps it
 *     waiting for the bank, charging nothing; coming back unanswered is a
 *     refusal that releases the authorization. Completing the bank's
 *     confirmation needs a browser and is the manual check in the live
 *     verification queue.
 *
 * Runs on the copied test database. Every Stripe subscription and
 * authorization it makes is cancelled before it ends.
 *
 * Run on dev: php tests/run.php live --only=plugins/store/tests/card_checkout_stripe_live_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();
harness_test_mode();
set_time_limit(900);

require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/ShoppingCart.php'));
require_once(PathHelper::getIncludePath('plugins/store/logic/product_logic.php'));
require_once(PathHelper::getIncludePath('plugins/store/logic/cart_charge_logic.php'));

$settings = Globalvars::get_instance();
$run_id = substr(md5(uniqid('ccl', true)), 0, 8);

$admin = make_user('CclAdmin' . $run_id, 10);
function ccl_as_admin() {
	global $admin;
	$_SESSION['loggedin'] = 1;
	$_SESSION['usr_user_id'] = (int)$admin->key;
	$_SESSION['permission'] = 10;
}
ccl_as_admin();
$_SESSION['test_mode'] = true;
$_SESSION['send_emails'] = false;

// ===========================================================================
section('Preconditions');
// ===========================================================================
$pk = (string)$settings->get_setting('stripe_api_key_test');
$sk = (string)$settings->get_setting('stripe_api_pkey_test');
check($pk !== '' && $sk !== '', 'Stripe test keys configured');
$no_live_keys = strpos($pk, 'live_') === false && strpos($sk, 'live_') === false;
check($no_live_keys, 'no live Stripe key in the test key settings', $no_live_keys ? '' : 'LIVE key detected; refusing to charge');
check((string)$settings->get_setting('checkout_type') === 'stripe_regular', 'the card form is the checkout');
check(StripeHelper::isTestMode(), 'Stripe is in test mode');
if ($pk === '' || $sk === '' || !$no_live_keys || !StripeHelper::isTestMode()) {
	harness_finish();
}

$stripe = new StripeHelper();
$client = $stripe->get_stripe_client();

// Whatever this run makes at Stripe is taken down at the end, pass or fail.
$made_subscriptions = array();
$made_intents = array();
harness_defer(function () use (&$made_subscriptions, &$made_intents, $client) {
	foreach (array_unique($made_subscriptions) as $id) {
		try {
			$sub = $client->subscriptions->retrieve($id);
			if (!in_array($sub->status, array('canceled', 'incomplete_expired'), true)) { $client->subscriptions->cancel($id); }
		} catch (Throwable $e) { echo "  WARNING: subscription $id not cancelled: " . $e->getMessage() . "\n"; }
	}
	foreach (array_unique($made_intents) as $id) {
		try {
			$pi = $client->paymentIntents->retrieve($id);
			if (in_array($pi->status, array('requires_capture', 'requires_action', 'requires_payment_method', 'requires_confirmation'), true)) {
				$client->paymentIntents->cancel($id);
			}
		} catch (Throwable $e) { echo "  WARNING: payment $id not cancelled: " . $e->getMessage() . "\n"; }
	}
});

// ---------------------------------------------------------------------------
// Products: made through the admin logic, which makes their Stripe products
// ---------------------------------------------------------------------------

function ccl_product($name, $price, $price_type) {
	$result = harness_call_logic_ok('plugins/store/admin/logic/admin_product_edit_logic.php', 'admin_product_edit_logic', array(
		'action' => 'add', 'json_confirm' => '1', 'pro_name' => $name,
		'pro_description' => 'card_checkout_stripe_live fixture', 'pro_short_description' => 'fixture',
		'pro_is_active' => '1', 'pro_max_purchase_count' => '0', 'pro_max_cart_count' => '0', 'pro_expires' => '0',
		'pro_link' => 'ccl-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)),
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

$domain_id = ccl_product('CCL Domain ' . $run_id, '20.00', 'single');
$hosting_id = ccl_product('CCL Hosting ' . $run_id, '12.99', 'month');
$extra_id = ccl_product('CCL Extra ' . $run_id, '5.00', 'month');
check($domain_id && $hosting_id && $extra_id, 'a one-time and two monthly products are made');
check((string)(new Product($domain_id, TRUE))->get('pro_stripe_product_id_test') !== '', 'with their Stripe products');

/** Fill a fresh cart for a fresh buyer. */
function ccl_cart(array $product_ids, $label) {
	ccl_as_admin();
	ShoppingCart::current()->clear_cart();
	unset($_SESSION[StripeHelper::CARD_PENDING_KEY]);
	$email = harness_fixture_email('ccl_' . $label . '_' . $GLOBALS['run_id']);
	foreach ($product_ids as $product_id) {
		$product = new Product($product_id, TRUE);
		$post = array('product_id' => $product_id, 'product_version' => $product->get_product_versions(TRUE)->get(0)->key, 'cart' => '1',
			'full_name_first' => 'Card', 'full_name_last' => 'Live', 'email' => $email);
		$original_post = $_POST; $original_request = $_REQUEST;
		$_POST = $post; $_REQUEST = $post;
		try { product_logic($post); }
		finally { $_POST = $original_post; $_REQUEST = $original_request; }
	}
	ShoppingCart::current()->billing_user_prefill_from_items();
	// The checkout signs a new buyer in; each section's buyer is new.
	unset($_SESSION['loggedin'], $_SESSION['usr_user_id'], $_SESSION['permission']);
	return (int)DbConnector::get_instance()->get_db_link()->query('SELECT COALESCE(MAX(ord_order_id), 0) FROM ord_orders')->fetchColumn();
}

/** Post to /cart_charge as the card form does. Returns [redirect, the order made, its messages]. */
function ccl_charge($watermark, array $post) {
	global $made_subscriptions, $made_intents;
	unset($_SESSION['saved_messages']);
	$original = array($_POST, $_REQUEST, $_GET, $_SERVER['REQUEST_METHOD'] ?? null);
	$_POST = $post; $_REQUEST = $post; $_GET = array();
	$_SERVER['REQUEST_METHOD'] = 'POST';
	try { $result = cart_charge_logic($post); }
	finally {
		list($_POST, $_REQUEST, $_GET, $method) = $original;
		if ($method === null) unset($_SERVER['REQUEST_METHOD']); else $_SERVER['REQUEST_METHOD'] = $method;
	}
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare('SELECT ord_order_id FROM ord_orders WHERE ord_order_id > ? ORDER BY ord_order_id DESC LIMIT 1');
	$q->execute(array($watermark));
	$order = null;
	if ($order_id = $q->fetchColumn()) {
		$order = new Order((int)$order_id, TRUE);
		harness_register_row('ord_orders', 'ord_order_id', $order->key);
		foreach (new MultiOrderItem(array('odi_ord_order_id' => $order->key)) as $item) {
			harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);
			if ($item->get('odi_stripe_subscription_id')) $made_subscriptions[] = $item->get('odi_stripe_subscription_id');
		}
		if ($order->get('ord_stripe_payment_intent_id')) $made_intents[] = $order->get('ord_stripe_payment_intent_id');
		$user = new User((int)$order->get('ord_usr_user_id'), TRUE);
		if ($user->key) harness_register_user($user);
	}
	$pending = $_SESSION[StripeHelper::CARD_PENDING_KEY] ?? null;
	if (is_array($pending)) {
		if (!empty($pending['state']['intent'])) $made_intents[] = $pending['state']['intent'];
		foreach ((array)($pending['state']['subscriptions'] ?? array()) as $id) $made_subscriptions[] = $id;
	}
	$messages = array();
	foreach ((array)($_SESSION['saved_messages'] ?? array()) as $m) {
		$messages[] = is_object($m) ? (string)($m->message_text ?? $m->message ?? json_encode((array)$m)) : (string)$m;
	}
	return array($result->redirect, $order, implode(' | ', $messages));
}

function ccl_pay($watermark, $payment_method) {
	return ccl_charge($watermark, array('stripePaymentMethod' => $payment_method, 'password' => ''));
}

/** Everything Stripe holds for the buyer of $order: their payments and subscriptions. */
function ccl_at_stripe($order) {
	global $client, $made_subscriptions, $made_intents;
	$customer = (new StripeHelper())->get_stripe_customer_id(new User((int)$order->get('ord_usr_user_id'), TRUE));
	$intents = $customer ? $client->paymentIntents->all(array('customer' => $customer, 'limit' => 20))->data : array();
	$subs = $customer ? $client->subscriptions->all(array('customer' => $customer, 'status' => 'all', 'limit' => 20))->data : array();
	foreach ($intents as $pi) $made_intents[] = $pi->id;
	foreach ($subs as $sub) $made_subscriptions[] = $sub->id;
	return array($intents, $subs);
}

function ccl_items($order) {
	$items = array();
	if ($order) { foreach (new MultiOrderItem(array('odi_ord_order_id' => $order->key)) as $item) { $items[] = $item; } }
	return $items;
}

function ccl_status($order) {
	if (!$order) return -1;
	$order->load();
	return (int)$order->get('ord_status');
}

// ===========================================================================
section('A one-time cart');
// ===========================================================================
$w = ccl_cart(array($domain_id), 'ot');
list($redirect, $order, $messages) = ccl_pay($w, 'pm_card_visa');
check($redirect !== '/checkout' && ccl_status($order) === Order::STATUS_PAID, 'completes and is paid', $messages);
$pi = $order ? $client->paymentIntents->retrieve((string)$order->get('ord_stripe_payment_intent_id')) : null;
check($pi && $pi->capture_method === 'manual' && $pi->status === 'succeeded' && (int)$pi->amount_received === 2000,
	'Stripe shows the 20.00 authorized and then captured', $pi ? $pi->status . ' ' . $pi->amount_received : 'no payment');
check($pi && (string)$order->get('ord_stripe_charge_id') === (string)$pi->latest_charge, 'the order names its charge');

// ===========================================================================
section('A subscription plus a one-time line, then refunds');
// ===========================================================================
$w = ccl_cart(array($hosting_id, $domain_id), 'mx');
list($redirect, $order, $messages) = ccl_pay($w, 'pm_card_visa');
check($redirect !== '/checkout' && ccl_status($order) === Order::STATUS_PAID && count(ccl_items($order)) === 2,
	'completes, paid, with both lines', $messages);
$hosting_line = null; $domain_line = null;
foreach (ccl_items($order) as $item) {
	if ($item->get('odi_is_subscription')) $hosting_line = $item; else $domain_line = $item;
}
$sub = $hosting_line ? $client->subscriptions->retrieve((string)$hosting_line->get('odi_stripe_subscription_id'), array('expand' => array('latest_invoice'))) : null;
check($sub && $sub->status === 'active' && (int)$sub->latest_invoice->amount_paid === 1299, 'the subscription is live and its first 12.99 paid',
	$sub ? $sub->status : 'no subscription');
$pi = $order ? $client->paymentIntents->retrieve((string)$order->get('ord_stripe_payment_intent_id')) : null;
check($pi && $pi->status === 'succeeded' && (int)$pi->amount_received === 2000 && $pi->setup_future_usage === 'off_session',
	'the 20.00 was authorized with the card saved for later, and captured');
check($hosting_line && trim((string)$hosting_line->get('odi_subscription_period_end')) !== '', 'the period end is recorded');

ccl_as_admin();
if ($hosting_line && $domain_line) {
	$refund_line = new OrderItem((int)$domain_line->key, TRUE);
	$r1 = $refund_line->refund_payment(5, 'live test');
	$charge = $client->charges->retrieve((string)$order->get('ord_stripe_charge_id'));
	check((int)$charge->amount_refunded === 500, 'a 5.00 refund of the domain line comes out of the domain charge', (string)$charge->amount_refunded);

	$refund_line = new OrderItem((int)$hosting_line->key, TRUE);
	$r2 = $refund_line->refund_payment(12.99, 'live test', true);
	$invoice_charge = $client->charges->retrieve((string)$sub->latest_invoice->charge);
	check((int)$invoice_charge->amount_refunded === 1299, 'the hosting refund comes out of the subscription\'s invoice', (string)$invoice_charge->amount_refunded);
	$sub_after = $client->subscriptions->retrieve((string)$hosting_line->get('odi_stripe_subscription_id'));
	check($r2['cancelled'] === true && $sub_after->status === 'canceled', 'and refund-and-cancel cancels it at Stripe', $sub_after->status);
	$order->load();
	check(abs((float)$order->get('ord_refund_amount') - 17.99) < 0.001, 'the order\'s refund total is 17.99', (string)$order->get('ord_refund_amount'));
}

// ===========================================================================
section('A subscription alone');
// ===========================================================================
$w = ccl_cart(array($hosting_id), 'so');
list($redirect, $order, $messages) = ccl_pay($w, 'pm_card_visa');
check($redirect !== '/checkout' && ccl_status($order) === Order::STATUS_PAID, 'completes and is paid', $messages);
list($intents, $subs) = $order ? ccl_at_stripe($order) : array(array(), array());
check(count($subs) === 1 && $subs[0]->status === 'active', 'one live subscription at Stripe');

// ===========================================================================
section('Declines leave nothing charged or live');
// ===========================================================================
$w = ccl_cart(array($domain_id), 'dc');
list($redirect, $order, $messages) = ccl_pay($w, 'pm_card_chargeDeclined');
check($redirect === '/checkout' && stripos($messages, 'declined') !== false && ccl_status($order) === Order::STATUS_ERROR,
	'a declined card goes back to checkout with the reason, the order in error', $messages);
list($intents, $subs) = $order ? ccl_at_stripe($order) : array(array(), array());
$taken = 0; foreach ($intents as $p) { $taken += (int)$p->amount_received; }
check($taken === 0, 'nothing taken', (string)$taken);

$w = ccl_cart(array($hosting_id, $domain_id), 'cf');
list($redirect, $order, $messages) = ccl_pay($w, 'pm_card_chargeCustomerFail');
check($redirect === '/checkout' && ccl_status($order) === Order::STATUS_ERROR, 'a card that saves but is declined on the amount is refused', $messages);
list($intents, $subs) = $order ? ccl_at_stripe($order) : array(array(), array());
$taken = 0; foreach ($intents as $p) { $taken += (int)$p->amount_received; }
$live = 0; foreach ($subs as $s) { if (in_array($s->status, array('active', 'trialing', 'past_due'), true)) $live++; }
check($taken === 0 && $live === 0, 'nothing taken, no live subscription', "taken $taken, live $live");

$w = ccl_cart(array($hosting_id), 'nf');
list($redirect, $order, $messages) = ccl_pay($w, 'pm_card_chargeDeclinedInsufficientFunds');
check($redirect === '/checkout' && stripos($messages, 'insufficient') !== false && ccl_status($order) === Order::STATUS_ERROR,
	'insufficient funds on a subscription is refused with why', $messages);
list($intents, $subs) = $order ? ccl_at_stripe($order) : array(array(), array());
$live = 0; foreach ($subs as $s) { if (!in_array($s->status, array('canceled', 'incomplete_expired'), true)) $live++; }
check($live === 0, 'and its subscription is cancelled at Stripe', (string)$live);

// ===========================================================================
section('3-D Secure');
// ===========================================================================
$w = ccl_cart(array($hosting_id, $domain_id), 'ds');
list($redirect, $order, $messages) = ccl_pay($w, 'pm_card_authenticationRequired');
$pending = $_SESSION[StripeHelper::CARD_PENDING_KEY] ?? null;
check($redirect === '/checkout' && !empty($pending['client_secret']) && ccl_status($order) === Order::STATUS_UNPAID,
	'a card whose bank always asks stops the checkout, waiting for the bank', $messages);
$pi = !empty($pending['state']['intent']) ? $client->paymentIntents->retrieve($pending['state']['intent']) : null;
check($pi && $pi->status === 'requires_action' && (int)$pi->amount_received === 0, 'Stripe holds the payment waiting for the bank, nothing taken',
	$pi ? $pi->status : 'no payment');
check(empty($pending['state']['subscriptions']), 'no subscription is made before the bank answers');

list($redirect, $order, $messages) = ccl_charge($w, array('card_payment_confirm' => '1'));
check($redirect === '/checkout' && stripos($messages, 'did not confirm') !== false && ccl_status($order) === Order::STATUS_ERROR,
	'coming back without the bank\'s answer is refused', $messages);
$pi = $pi ? $client->paymentIntents->retrieve($pi->id) : null;
check($pi && $pi->status === 'canceled', 'and the waiting payment is released at Stripe', $pi ? $pi->status : '');

// ===========================================================================
section('One subscription per card checkout');
// ===========================================================================
$w = ccl_cart(array($hosting_id, $extra_id), 'two');
list($redirect, $order, $messages) = ccl_pay($w, 'pm_card_visa');
check($redirect === '/checkout' && strpos($messages, 'one subscription at a time') !== false, 'two subscriptions are refused', $messages);

ShoppingCart::current()->clear_cart();
unset($_SESSION[StripeHelper::CARD_PENDING_KEY]);
harness_finish();
