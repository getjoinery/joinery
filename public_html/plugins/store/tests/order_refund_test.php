<?php
/** @joinery-test
 * name: order_refund
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A refund comes out of the payment that paid the line (B125, B151).
 *
 * specs/easy_signup_completion.md WP1b: a subscription line is refunded from
 * the subscription's own latest invoice, never the order's one-time charge; a
 * subscription-only order can be refunded at all; a refund can cancel the
 * subscription with it; the order's refund total is the lines' refunds, in
 * cents, not rounded to whole units.
 *
 * Stripe is doubled through OrderItem's stripe_helper() seam; nothing
 * reaches Stripe.
 *
 * Run: php plugins/store/tests/order_refund_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));

class RfFakeSubscriptions {
	public $invoices = array();
	public function retrieve($id, $params = array()) {
		$invoice = $this->invoices[$id] ?? array('id' => 'in_none', 'object' => 'invoice', 'charge' => null, 'number' => null, 'created' => time());
		return \Stripe\Subscription::constructFrom(array('id' => $id, 'object' => 'subscription', 'latest_invoice' => $invoice));
	}
}

class RfStripe extends StripeHelper {
	public $charges = array();
	public $intents = array();
	public $refunds = array();
	public $cancels = array();
	public $refuse_cancel = false;
	public $subscriptions;
	public function __construct() {
		$this->test_mode = true;
		$this->subscriptions = new RfFakeSubscriptions();
		$client = new stdClass();
		$client->subscriptions = $this->subscriptions;
		$prop = new ReflectionProperty('StripeHelper', 'stripe');
		$prop->setValue($this, $client);
	}
	public function add_charge($id, $amount_cents) {
		$this->charges[$id] = \Stripe\Charge::constructFrom(array('id' => $id, 'object' => 'charge', 'amount' => $amount_cents, 'amount_refunded' => 0));
	}
	// A fresh copy each time, as Stripe answers: a refund made after the read
	// does not change the copy already in hand.
	public function get_charge($id) { return clone $this->charges[$id]; }
	public function get_charge_from_payment_intent($id) { return isset($this->intents[$id]) ? clone $this->charges[$this->intents[$id]] : false; }
	public function refund_charge($id, $amount) {
		$this->refunds[] = array($id, $amount);
		$this->charges[$id]->amount_refunded += (int)round($amount * 100);
		return (object)array('id' => 're_' . count($this->refunds));
	}
	public function cancel_subscription($id, $type) {
		$this->cancels[] = array($id, $type);
		return $this->refuse_cancel ? false : (object)array('id' => $id);
	}
	public function update_subscription_in_order_item($item) {
		$item->set('odi_subscription_status', 'canceled');
		$item->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s'));
		$item->save();
		return true;
	}
}

class RfOrderItem extends OrderItem {
	public static $stripe;
	protected function stripe_helper() { return self::$stripe; }
	protected function send_notice($to, array $values) {}
}

$admin = make_user('RfAdmin', 10);
$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = (int)$admin->key;
$_SESSION['permission'] = 10;
$buyer = make_user('RfBuyer');

function rf_order(array $fields) {
	global $buyer;
	$order = new Order(NULL);
	$order->set('ord_usr_user_id', $buyer->key);
	$order->set('ord_test_mode', true);
	foreach ($fields as $k => $v) { $order->set($k, $v); }
	$order->save();
	$order->load();
	harness_register_row('ord_orders', 'ord_order_id', $order->key);
	return $order;
}

function rf_line($order, $price, array $fields = array()) {
	global $buyer;
	$item = new OrderItem(NULL);
	$item->set('odi_ord_order_id', $order->key);
	$item->set('odi_pro_product_id', 999999003);
	$item->set('odi_usr_user_id', $buyer->key);
	$item->set('odi_price', $price);
	$item->set('odi_status', OrderItem::STATUS_PAID);
	$item->set('odi_is_subscription', false);
	foreach ($fields as $k => $v) { $item->set($k, $v); }
	$item->save();
	$item->load();
	harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);
	return new RfOrderItem((int)$item->key, TRUE);
}

function rf_throws(callable $fn): string {
	try { $fn(); } catch (Throwable $e) { return $e->getMessage(); }
	return '';
}

// ===========================================================================
section('A hosting order: a domain charge and a subscription paid by its own invoice');
// ===========================================================================
$stripe = new RfStripe();
RfOrderItem::$stripe = $stripe;
$stripe->add_charge('ch_domain', 2000);
$stripe->add_charge('ch_invoice', 1299);
$stripe->subscriptions->invoices['sub_host'] = array('id' => 'in_host', 'object' => 'invoice', 'charge' => 'ch_invoice', 'number' => 'INV-1', 'created' => time());

$order = rf_order(array('ord_stripe_charge_id' => 'ch_domain', 'ord_total_cost' => '32.99'));
$domain = rf_line($order, '20.00');
$hosting = rf_line($order, '12.99', array('odi_is_subscription' => true, 'odi_stripe_subscription_id' => 'sub_host',
	'odi_payment_source' => 'stripe', 'odi_subscription_status' => 'active'));

$found = $stripe->refundable_charge_for_item($hosting);
check(($found['charge']->id ?? '') === 'ch_invoice' && $found['is_order_charge'] === false,
	'the hosting line is refunded from its subscription\'s invoice, not the domain charge', json_encode(array_keys($found)));
$found = $stripe->refundable_charge_for_item($domain);
check(($found['charge']->id ?? '') === 'ch_domain', 'the domain line from the order\'s charge');

$result = $domain->refund_payment(5, 'partial');
check($stripe->refunds === array(array('ch_domain', 5.0)), 'a partial domain refund goes to the domain charge', json_encode($stripe->refunds));
$domain->load();
check(abs((float)$domain->get('odi_refund_amount') - 5.0) < 0.001, 'and is recorded on its line');
check(abs($domain->refundable_amount($stripe->charges['ch_domain']) - 15.0) < 0.001, 'which can refund 15.00 more');
$over = rf_throws(function () use ($domain) { $domain->refund_payment(16); });
check(strpos($over, 'at most 15.00') !== false && count($stripe->refunds) === 1,
	'more than is left is refused before Stripe is asked', $over);

$result = $hosting->refund_payment(12.99, 'customer left', true);
check(end($stripe->refunds) === array('ch_invoice', 12.99), 'the hosting refund goes to its invoice\'s charge', json_encode($stripe->refunds));
check($stripe->cancels === array(array('sub_host', 'immediate')), 'refund and cancel cancels the subscription now', json_encode($stripe->cancels));
check($result['cancelled'] === true, 'and says so');
$order->load();
check(abs((float)$order->get('ord_refund_amount') - 17.99) < 0.001,
	'the order\'s refund total is 17.99, in cents (B151)', (string)$order->get('ord_refund_amount'));

$order->refresh_refund_amount(8.0);
check(abs((float)$order->get('ord_refund_amount') - 20.99) < 0.001,
	'a larger refund of the domain charge made in Stripe\'s dashboard is counted', (string)$order->get('ord_refund_amount'));

// ===========================================================================
section('A subscription-only order can be refunded');
// ===========================================================================
$stripe->add_charge('ch_only', 1299);
$stripe->subscriptions->invoices['sub_only'] = array('id' => 'in_only', 'object' => 'invoice', 'charge' => 'ch_only', 'number' => 'INV-2', 'created' => time());
$sub_order = rf_order(array('ord_total_cost' => '12.99'));
$sub_line = rf_line($sub_order, '12.99', array('odi_is_subscription' => true, 'odi_stripe_subscription_id' => 'sub_only', 'odi_payment_source' => 'stripe'));
$sub_line->refund_payment(12.99);
check(end($stripe->refunds) === array('ch_only', 12.99), 'from its invoice, with no charge on the order');
check($stripe->cancels === array(array('sub_host', 'immediate')), 'and without cancelling unless asked');

$stripe->subscriptions->invoices['sub_trial'] = array('id' => 'in_trial', 'object' => 'invoice', 'charge' => null, 'number' => null, 'created' => time());
$trial_line = rf_line($sub_order, '12.99', array('odi_is_subscription' => true, 'odi_stripe_subscription_id' => 'sub_trial', 'odi_payment_source' => 'stripe'));
$why = rf_throws(function () use ($trial_line) { $trial_line->refund_payment(1); });
check(strpos($why, 'no payment to refund') !== false, 'a subscription with nothing paid says so', $why);

// ===========================================================================
section('What Stripe already knows, and a cancel Stripe refuses');
// ===========================================================================
$stripe->add_charge('ch_dash', 2000);
$stripe->charges['ch_dash']->amount_refunded = 800;   // refunded in Stripe's dashboard
$dash_order = rf_order(array('ord_stripe_charge_id' => 'ch_dash', 'ord_total_cost' => '20.00'));
$dash_line = rf_line($dash_order, '20.00');
$dash_line->refund_payment(5);
$dash_order->load();
check(abs((float)$dash_order->get('ord_refund_amount') - 13.0) < 0.001,
	'a refund here keeps an earlier dashboard refund in the order total (R6)', (string)$dash_order->get('ord_refund_amount'));

$stripe->add_charge('ch_nocancel', 1299);
$stripe->subscriptions->invoices['sub_nocancel'] = array('id' => 'in_nc', 'object' => 'invoice', 'charge' => 'ch_nocancel', 'number' => 'INV-3', 'created' => time());
$nc_order = rf_order(array('ord_total_cost' => '12.99'));
$nc_line = rf_line($nc_order, '12.99', array('odi_is_subscription' => true, 'odi_stripe_subscription_id' => 'sub_nocancel', 'odi_payment_source' => 'stripe'));
$stripe->refuse_cancel = true;
$result = $nc_line->refund_payment(12.99, '', true);
$stripe->refuse_cancel = false;
$nc_line->load();
check(end($stripe->refunds) === array('ch_nocancel', 12.99) && abs((float)$nc_line->get('odi_refund_amount') - 12.99) < 0.001,
	'a refused cancel leaves the refund made and recorded (R8)');
check($result['cancelled'] === false && strpos((string)$result['cancel_error'], 'unable to cancel') !== false,
	'and is reported beside it', json_encode($result));

// ===========================================================================
section('Other payments');
// ===========================================================================
$stripe->add_charge('ch_hosted', 4500);
$stripe->intents['pi_hosted'] = 'ch_hosted';
$hosted_order = rf_order(array('ord_stripe_payment_intent_id' => 'pi_hosted', 'ord_total_cost' => '45.00'));
$hosted_line = rf_line($hosted_order, '45.00');
$hosted_line->refund_payment(45);
check(end($stripe->refunds) === array('ch_hosted', 45.0), 'a Stripe Checkout order is refunded from its payment\'s charge');

$paypal_order = rf_order(array('ord_paypal_order_id' => 'PP-RF-1', 'ord_total_cost' => '10.00'));
$paypal_line = rf_line($paypal_order, '10.00');
$why = rf_throws(function () use ($paypal_line) { $paypal_line->refund_payment(1); });
check(strpos($why, 'PayPal') !== false, 'a PayPal order is sent to PayPal', $why);

$live_order = rf_order(array('ord_test_mode' => false, 'ord_stripe_charge_id' => 'ch_domain', 'ord_total_cost' => '20.00'));
$live_line = rf_line($live_order, '20.00');
$why = rf_throws(function () use ($live_line) { $live_line->refund_payment(1); });
check(strpos($why, 'live mode') !== false, 'a live order is not refunded from a site in test mode', $why);

harness_finish();
