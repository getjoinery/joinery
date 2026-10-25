<?php
/** @joinery-test
 * name: stripe_webhook_invoice
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The Stripe webhook's invoice events, driven over real HTTP with events signed
 * by the site's stripe_endpoint_secret (stripe_webhook covers checkout and the
 * subscription events; these two had no test).
 *
 *   - invoice.payment_failed marks the line past_due;
 *   - invoice.payment_succeeded marks it active again and takes the period end.
 *
 * Each is a save with no one signed in, under the declared-permissions save
 * check. The endpoint answers 200 even when its handler throws (it logs the
 * error and the event), so the stored status, not the status code, is what
 * proves the save happened.
 *
 * The line has no user, so the failure email (sent to the subscriber) is never
 * sent from here.
 *
 * Run: php plugins/store/tests/stripe_webhook_invoice_test.php [base_url] [origin_ip]
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/http.php');
harness_http_boot(isset($argv) ? $argv : array());
harness_boot();

$run_id = substr(md5(uniqid('swi', true)), 0, 8);

$secret = Globalvars::get_instance()->get_setting('stripe_endpoint_secret');
if (!$secret || strpos($secret, 'whsec_') !== 0) {
	harness_skip('invoice events', 'stripe_endpoint_secret not configured on this site');
	harness_finish();
	return;
}

function swi_post(string $id, string $type, array $object, string $secret): array {
	$payload = json_encode(array('id' => $id, 'object' => 'event', 'api_version' => '2022-11-15',
		'created' => time(), 'type' => $type, 'data' => array('object' => $object)));
	$t = time();
	return harness_request('POST', '/ajax/stripe_webhook', array(
		'accept' => null,
		'headers' => array('Content-Type: application/json',
			'Stripe-Signature: t=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, $secret)),
		'body' => $payload, 'encode' => 'raw'));
}

/** What the endpoint logged for an event: [succeeded, error]. */
function swi_logged(string $event_id): array {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		'SELECT wbh_processed, wbh_error_message FROM wbh_webhook_logs WHERE wbh_event_id = ? ORDER BY 1 DESC LIMIT 1');
	$q->execute(array($event_id));
	$row = $q->fetch(PDO::FETCH_NUM);
	return $row ? array((bool)$row[0], (string)$row[1]) : array(null, 'not logged');
}

harness_defer(function () use ($run_id) {
	$q = DbConnector::get_instance()->get_db_link()->prepare('DELETE FROM wbh_webhook_logs WHERE wbh_event_id LIKE ?');
	$q->execute(array('evt_swi_' . $run_id . '%'));
});

$product = new Product(NULL);
$product->set('pro_name', 'HarnessTest SWI Plan ' . $run_id);
$product->set('pro_link', 'swi-plan-' . $run_id);
$product->set('pro_is_active', false);
$product->save();
$product->load();

$order = new Order(NULL);
$order->set('ord_total_cost', 9.00);
$order->set('ord_status', Order::STATUS_PAID);
$order->save();
$order->load();
$sub_id = 'sub_swi_' . $run_id;
$line = new OrderItem(NULL);
$line->set('odi_ord_order_id', $order->key);
$line->set('odi_pro_product_id', $product->key);
$line->set('odi_stripe_subscription_id', $sub_id);
$line->set('odi_subscription_status', 'active');
$line->save();
$line->load();
harness_register_row('pro_products', 'pro_product_id', $product->key);
harness_register_row('ord_orders', 'ord_order_id', $order->key);
harness_register_row('odi_order_items', 'odi_order_item_id', $line->key);

section('invoice.payment_failed');

$evt = 'evt_swi_' . $run_id . '_failed';
$r = swi_post($evt, 'invoice.payment_failed', array('id' => 'in_swi_' . $run_id . '_f', 'object' => 'invoice',
	'subscription' => $sub_id), $secret);
check($r['status'] === 200, 'accepted (200)', 'status ' . $r['status']);
$line->load();
list($ok, $err) = swi_logged($evt);
check($line->get('odi_subscription_status') === 'past_due', 'the line is past_due: the save was made with no one signed in',
	'status ' . $line->get('odi_subscription_status') . '; logged: ' . $err);
check($ok === true, 'and the endpoint logged the event as handled', $err);

section('invoice.payment_succeeded');

$period_end = time() + 30 * 86400;
$evt = 'evt_swi_' . $run_id . '_paid';
$r = swi_post($evt, 'invoice.payment_succeeded', array('id' => 'in_swi_' . $run_id . '_p', 'object' => 'invoice',
	'subscription' => $sub_id,
	'lines' => array('object' => 'list', 'data' => array(array('object' => 'line_item',
		'period' => array('start' => time(), 'end' => $period_end))))), $secret);
check($r['status'] === 200, 'accepted (200)', 'status ' . $r['status']);
$line->load();
list($ok, $err) = swi_logged($evt);
check($line->get('odi_subscription_status') === 'active', 'the line is active again',
	'status ' . $line->get('odi_subscription_status') . '; logged: ' . $err);
check(strtotime((string)$line->get('odi_subscription_period_end') . ' UTC') === $period_end,
	'and carries the invoice\'s period end', (string)$line->get('odi_subscription_period_end'));
check($ok === true, 'and the endpoint logged the event as handled', $err);

harness_finish();
