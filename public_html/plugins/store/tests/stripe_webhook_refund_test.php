<?php
/** @joinery-test
 * name: stripe_webhook_refund
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * charge.refunded, driven over real HTTP with an event signed by the site's
 * stripe_endpoint_secret: a refund made anywhere, Stripe's dashboard included,
 * lands on its order at once instead of waiting for an admin to open the
 * order page.
 *
 *   - found by the charge id, else by the payment intent;
 *   - ord_refund_amount is the charge's total refunded so far, so a second,
 *     larger refund raises it and a repeat changes nothing;
 *   - ord_refund_time is set once and kept.
 *
 * Run: php plugins/store/tests/stripe_webhook_refund_test.php [base_url] [origin_ip]
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/http.php');
harness_http_boot(isset($argv) ? $argv : array());
harness_boot();

$run_id = substr(md5(uniqid('swr', true)), 0, 8);

$secret = Globalvars::get_instance()->get_setting('stripe_endpoint_secret');
if (!$secret || strpos($secret, 'whsec_') !== 0) {
	harness_skip('charge.refunded', 'stripe_endpoint_secret not configured on this site');
	harness_finish();
	return;
}

function swr_post(string $id, array $object, string $secret): array {
	$payload = json_encode(array('id' => $id, 'object' => 'event', 'api_version' => '2022-11-15',
		'created' => time(), 'type' => 'charge.refunded', 'data' => array('object' => $object)));
	$t = time();
	return harness_request('POST', '/ajax/stripe_webhook', array(
		'accept' => null,
		'headers' => array('Content-Type: application/json',
			'Stripe-Signature: t=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, $secret)),
		'body' => $payload, 'encode' => 'raw'));
}

harness_defer(function () use ($run_id) {
	$q = DbConnector::get_instance()->get_db_link()->prepare('DELETE FROM wbh_webhook_logs WHERE wbh_event_id LIKE ?');
	$q->execute(array('evt_swr_' . $run_id . '%'));
});

$by_charge = new Order(NULL);
$by_charge->set('ord_total_cost', 30.00);
$by_charge->set('ord_status', Order::STATUS_PAID);
$by_charge->set('ord_stripe_charge_id', 'ch_swr_' . $run_id);
$by_charge->save();
harness_register_row('ord_orders', 'ord_order_id', $by_charge->key);

$by_intent = new Order(NULL);
$by_intent->set('ord_total_cost', 12.00);
$by_intent->set('ord_status', Order::STATUS_PAID);
$by_intent->set('ord_stripe_payment_intent_id', 'pi_swr_' . $run_id);
$by_intent->save();
harness_register_row('ord_orders', 'ord_order_id', $by_intent->key);

section('a refund lands on the order found by its charge');
$r = swr_post('evt_swr_' . $run_id . '_1', array('id' => 'ch_swr_' . $run_id, 'object' => 'charge',
	'amount' => 3000, 'amount_refunded' => 1000, 'payment_intent' => null), $secret);
check($r['status'] === 200, 'accepted (200)', 'status ' . $r['status']);
$o = new Order($by_charge->key, TRUE);
check(abs((float)$o->get('ord_refund_amount') - 10.00) < 0.001, 'the order records 10.00 refunded', (string)$o->get('ord_refund_amount'));
$first_time = (string)$o->get('ord_refund_time');
check($first_time !== '', 'and the refund time');

section('a second refund raises the total; the time is kept');
swr_post('evt_swr_' . $run_id . '_2', array('id' => 'ch_swr_' . $run_id, 'object' => 'charge',
	'amount' => 3000, 'amount_refunded' => 3000, 'payment_intent' => null), $secret);
$o = new Order($by_charge->key, TRUE);
check(abs((float)$o->get('ord_refund_amount') - 30.00) < 0.001, 'the order records 30.00, the charge\'s total refunded', (string)$o->get('ord_refund_amount'));
check((string)$o->get('ord_refund_time') === $first_time, 'the first refund time is kept');

section('an order known only by its payment intent');
swr_post('evt_swr_' . $run_id . '_3', array('id' => 'ch_other_' . $run_id, 'object' => 'charge',
	'amount' => 1200, 'amount_refunded' => 1200, 'payment_intent' => 'pi_swr_' . $run_id), $secret);
$o = new Order($by_intent->key, TRUE);
check(abs((float)$o->get('ord_refund_amount') - 12.00) < 0.001, 'is found through the intent and records 12.00', (string)$o->get('ord_refund_amount'));

harness_finish();
