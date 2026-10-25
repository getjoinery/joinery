<?php
/** @joinery-test
 * name: stripe_charge_lookup
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * StripeHelper's charge lookups never break the page that asks.
 *
 *   - An order with no Stripe ids (PayPal, free, hand-made) has no charge:
 *     get_charge_from_order() says false, and the admin order page's refund
 *     refresh does nothing (it read amount_refunded off null, dev 2026-10-25
 *     19:05, order 26314).
 *   - A Stripe error is caught: the catches named the \Stripe\Exception
 *     namespace, which is no class, so none ever caught. A failed lookup says
 *     false; a refused refund throws StripeHelperException, which the refund
 *     page turns into 'The refund was not made', where it used to echo and exit.
 *   - No keys: the refresh leaves the stored total alone.
 *
 * Fake clients only; no order is saved and Stripe is never called.
 *
 * Run: php tests/run.php safe --filter=stripe_charge_lookup
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!class_exists('StripeHelper')) {
	harness_skip('stripe charge lookups', 'the store plugin is not active');
	harness_finish();
}

/** A helper whose client is $client (null: no keys), in $test_mode. */
function scl_helper($client, bool $test_mode): StripeHelper {
	$helper = new StripeHelper();
	$helper->test_mode = $test_mode;
	$prop = new ReflectionProperty('StripeHelper', 'stripe');
	$prop->setAccessible(true);
	$prop->setValue($helper, $client);
	return $helper;
}

/** An unsaved order in $test_mode with the given Stripe ids. */
function scl_order(bool $test_mode, array $ids = array()): Order {
	$order = new Order(NULL);
	$order->set('ord_test_mode', $test_mode);
	foreach ($ids as $column => $value) {
		$order->set($column, $value);
	}
	return $order;
}

$api_error = function () {
	return \Stripe\Exception\InvalidRequestException::factory('No such charge: ch_gone', 404);
};

/** A client whose every call fails as Stripe does for an unknown id. */
$failing = new class($api_error) {
	public $charges, $paymentIntents, $refunds;
	public function __construct($err) {
		$thrower = new class($err) {
			private $err;
			public function __construct($err) { $this->err = $err; }
			public function retrieve(...$a) { throw ($this->err)(); }
			public function create(...$a) { throw ($this->err)(); }
		};
		$this->charges = $this->paymentIntents = $this->refunds = $thrower;
	}
};

section('An order with no Stripe ids has no charge');

$mode = (bool)(new StripeHelper())->test_mode;
$helper = scl_helper($failing, $mode);
$plain = scl_order($mode);
check($helper->get_charge_from_order($plain) === false, 'get_charge_from_order says false, not null');
$warned = false;
set_error_handler(function () use (&$warned) { $warned = true; return true; });
$result = $helper->update_order_refund_amount_from_stripe($plain);
restore_error_handler();
check($result === false && !$warned, 'the refund refresh does nothing and raises no warning');

section('A Stripe error is caught');

$gone = scl_order($mode, array('ord_stripe_charge_id' => 'ch_gone'));
check($helper->get_charge_from_order($gone) === false, 'an unknown charge id: false, not a crash');
$intent = scl_order($mode, array('ord_stripe_payment_intent_id' => 'pi_gone'));
check($helper->get_charge_from_order($intent) === false, 'an unknown payment intent: false, not a crash');
check($helper->update_order_refund_amount_from_stripe($gone) === false, 'and the refresh leaves the order alone');

$said = '';
try {
	$helper->refund_charge('ch_gone', 5);
} catch (StripeHelperException $e) {
	$said = $e->getMessage();
}
check(strpos($said, 'No such charge') !== false, 'a refused refund throws StripeHelperException with Stripe\'s words', $said);

section('No keys');

$keyless = scl_helper(null, $mode);
check($keyless->update_order_refund_amount_from_stripe($gone) === false, 'the refresh with no keys leaves the stored total alone');

harness_finish();
