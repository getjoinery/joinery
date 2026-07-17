<?php
/** @joinery-test
 * name: stripe_webhook
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * Stripe webhook: signature verification, event dispatch, idempotency, and
 * entitlement revocation — the store's top money path, previously untested.
 *
 * Two seams, mirroring mobile_billing_test's split of crypto from engine:
 *
 *   1. The verification primitive the endpoint delegates to
 *      (\Stripe\Webhook::constructEvent) is exercised in-process with a
 *      self-generated secret — no configured secret, no HTTP: a correctly
 *      signed payload verifies; a tampered body, a wrong-secret signature, a
 *      stale timestamp (outside the 300s replay window), and a malformed
 *      header are each rejected.
 *
 *   2. The live endpoint (/ajax/stripe_webhook) is driven over real HTTP with
 *      constructed events signed by the site's configured stripe_endpoint_secret
 *      — the exact shape Stripe posts. A valid checkout marks an order paid; an
 *      invalid-signature post is refused with 400 and writes nothing; a replayed
 *      event id is suppressed (no second order); and customer.subscription.deleted
 *      cancels the order item and strips the subscriber's tier.
 *
 * The endpoint script exits() and defines a function, so it is built to run once
 * per request — the dispatch half is driven over HTTP (a real request populates
 * php://input), never by including the file.
 *
 * Run: php plugins/store/tests/stripe_webhook_test.php [base_url] [origin_ip]
 *
 * @version 1.0.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/http.php');
harness_http_boot(isset($argv) ? $argv : array());
harness_boot();

require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('data/webhook_logs_class.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/TierBilling.php'));
require_once(PathHelper::getComposerAutoloadPath()); // Stripe SDK for the signature seam

$run_id = substr(md5(uniqid('sw', true)), 0, 8);

// ---------------------------------------------------------------------------
// Helpers — build and sign events exactly as Stripe does.
// ---------------------------------------------------------------------------

/** The Stripe-Signature header: t=<ts>,v1=<hex hmac_sha256("<ts>.<payload>", secret)>. */
function sw_sign_header($payload, $secret, $timestamp = null) {
	$timestamp = ($timestamp === null) ? time() : $timestamp;
	$sig = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
	return 't=' . $timestamp . ',v1=' . $sig;
}

/** A minimal but well-formed Stripe event envelope as a JSON string. */
function sw_event($id, $type, array $object) {
	return json_encode(array(
		'id'          => $id,
		'object'      => 'event',
		'api_version' => '2022-11-15',
		'created'     => time(),
		'type'        => $type,
		'data'        => array('object' => $object),
	));
}

/** POST a raw signed body to the live webhook endpoint. */
function sw_post($payload, $sig_header) {
	return harness_request('POST', '/ajax/stripe_webhook', array(
		'accept'  => null,
		'headers' => array(
			'Content-Type: application/json',
			'Stripe-Signature: ' . $sig_header,
		),
		'body'    => $payload,
		'encode'  => 'raw',
	));
}

/** Count orders for a session id straight from the table (avoids Multi option-key drift). */
function sw_order_ids_for_session($session_id) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare('SELECT ord_order_id FROM ord_orders WHERE ord_stripe_session_id = ?');
	$q->execute(array($session_id));
	return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}

// Clean up every webhook-log row this run writes (endpoint logs each processed event).
harness_defer(function () use ($run_id) {
	$db = DbConnector::get_instance()->get_db_link();
	try {
		$q = $db->prepare('DELETE FROM wbh_webhook_logs WHERE wbh_event_id LIKE ?');
		$q->execute(array('evt_sw_' . $run_id . '%'));
	} catch (\Throwable $e) {
		echo "  WARNING: webhook_logs cleanup failed: " . $e->getMessage() . "\n";
	}
});

// ---------------------------------------------------------------------------
section('Signature verification seam (\Stripe\Webhook::constructEvent)');
// ---------------------------------------------------------------------------

if (!class_exists('\Stripe\Webhook')) {
	harness_skip('Stripe SDK not autoloadable — signature seam skipped');
} else {
	$seam_secret = 'whsec_seam_' . bin2hex(random_bytes(8));
	$seam_payload = sw_event('evt_seam_' . $run_id, 'ping.test', array('id' => 'obj_ok'));

	$good_header = sw_sign_header($seam_payload, $seam_secret);
	$event = \Stripe\Webhook::constructEvent($seam_payload, $good_header, $seam_secret);
	check($event->id === 'evt_seam_' . $run_id && $event->type === 'ping.test',
		'correctly signed payload verifies and decodes to the event');

	// Same signature, mutated body — the classic forgery attempt.
	$tampered = str_replace('obj_ok', 'obj_HACKED', $seam_payload);
	$threw = false;
	try { \Stripe\Webhook::constructEvent($tampered, $good_header, $seam_secret); }
	catch (\Stripe\Exception\SignatureVerificationException $e) { $threw = true; }
	check($threw, 'tampered body under the original signature is rejected');

	// Correct body, signature made with a different secret.
	$foreign_header = sw_sign_header($seam_payload, 'whsec_other_' . bin2hex(random_bytes(8)));
	$threw = false;
	try { \Stripe\Webhook::constructEvent($seam_payload, $foreign_header, $seam_secret); }
	catch (\Stripe\Exception\SignatureVerificationException $e) { $threw = true; }
	check($threw, 'signature computed with a foreign secret is rejected');

	// Valid signature but a timestamp outside the 300s tolerance — a replay.
	$stale_header = sw_sign_header($seam_payload, $seam_secret, time() - 3600);
	$threw = false;
	try { \Stripe\Webhook::constructEvent($seam_payload, $stale_header, $seam_secret); }
	catch (\Stripe\Exception\SignatureVerificationException $e) { $threw = true; }
	check($threw, 'signature with a stale timestamp (beyond 300s) is rejected');

	$threw = false;
	try { \Stripe\Webhook::constructEvent($seam_payload, 'garbage-header', $seam_secret); }
	catch (\Stripe\Exception\SignatureVerificationException $e) { $threw = true; }
	check($threw, 'malformed signature header is rejected');
}

// ---------------------------------------------------------------------------
section('Endpoint enforcement over HTTP');
// ---------------------------------------------------------------------------

$settings = Globalvars::get_instance();
$endpoint_secret = $settings->get_setting('stripe_endpoint_secret');

if (!$endpoint_secret || strpos($endpoint_secret, 'whsec_') !== 0) {
	harness_skip('stripe_endpoint_secret not configured on this site — endpoint HTTP cases skipped');
	harness_finish();
	return;
}

$subscriber = make_user('SwSub');

// --- valid checkout marks an order paid -----------------------------------

$sess_a = 'cs_test_sw_' . $run_id . '_a';
$payload_a = sw_event('evt_sw_' . $run_id . '_checkout', 'checkout.session.completed', array(
	'id'                  => $sess_a,
	'object'              => 'checkout.session',
	'amount_total'        => 4999,
	'currency'            => 'usd',
	'client_reference_id' => (string)$subscriber->key,
	'payment_intent'      => 'pi_sw_' . $run_id,
	'subscription'        => null,
));
$r = sw_post($payload_a, sw_sign_header($payload_a, $endpoint_secret));
check($r['status'] === 200, 'valid checkout.session.completed accepted (200)', 'status ' . $r['status']);

$order = Order::GetByStripeSession($sess_a);
check($order !== NULL && $order->key, 'an order was created for the checkout session');
if ($order !== NULL && $order->key) {
	harness_register_row('ord_orders', 'ord_order_id', $order->key);
	check((int)$order->get('ord_status') === Order::STATUS_PAID, 'order is marked STATUS_PAID');
	check((int)$order->get('ord_usr_user_id') === (int)$subscriber->key, 'order is linked to the client_reference_id user');
	check((float)$order->get('ord_total_cost') === 49.99, 'order total is amount_total / 100');
}

// --- invalid signature is refused and writes nothing ----------------------

$sess_b = 'cs_test_sw_' . $run_id . '_b';
$payload_b = sw_event('evt_sw_' . $run_id . '_badsig', 'checkout.session.completed', array(
	'id'                  => $sess_b,
	'object'              => 'checkout.session',
	'amount_total'        => 9999,
	'currency'            => 'usd',
	'client_reference_id' => (string)$subscriber->key,
	'payment_intent'      => 'pi_swbad_' . $run_id,
	'subscription'        => null,
));
$bad_header = sw_sign_header($payload_b, 'whsec_forged_' . $run_id); // signed with the wrong secret
$r = sw_post($payload_b, $bad_header);
check($r['status'] === 400, 'invalid-signature webhook is rejected (400)', 'status ' . $r['status']);
check(count(sw_order_ids_for_session($sess_b)) === 0, 'rejected event created no order');
check(!WebhookLog::isDuplicate('evt_sw_' . $run_id . '_badsig'), 'rejected event was not logged (never reached dispatch)');

// A post with no signature header at all is likewise a 400.
$r = sw_post($payload_b, '');
check($r['status'] === 400, 'webhook with no signature header is rejected (400)', 'status ' . $r['status']);

// --- a replayed event id is suppressed ------------------------------------

$sess_c = 'cs_test_sw_' . $run_id . '_c';
$payload_c = sw_event('evt_sw_' . $run_id . '_dup', 'checkout.session.completed', array(
	'id'                  => $sess_c,
	'object'              => 'checkout.session',
	'amount_total'        => 1500,
	'currency'            => 'usd',
	'client_reference_id' => (string)$subscriber->key,
	'payment_intent'      => 'pi_swdup_' . $run_id,
	'subscription'        => null,
));
$header_c = sw_sign_header($payload_c, $endpoint_secret);

$r1 = sw_post($payload_c, $header_c);
check($r1['status'] === 200, 'first delivery of the replay-test event accepted (200)', 'status ' . $r1['status']);
foreach (sw_order_ids_for_session($sess_c) as $oid) {
	harness_register_row('ord_orders', 'ord_order_id', $oid);
}
check(WebhookLog::isDuplicate('evt_sw_' . $run_id . '_dup'), 'processed event id is recorded for idempotency');

$r2 = sw_post($payload_c, $header_c); // byte-identical replay
check($r2['status'] === 200, 'replayed event returns 200 (suppressed, not an error)', 'status ' . $r2['status']);
check(count(sw_order_ids_for_session($sess_c)) === 1, 'the replay created no second order', 'orders: ' . count(sw_order_ids_for_session($sess_c)));

// --- customer.subscription.deleted revokes the tier -----------------------

$tier = new SubscriptionTier(NULL);
$tier->set('sbt_name', 'sw_tier_' . $run_id);
$tier->set('sbt_display_name', 'SW Test Tier');
$tier->set('sbt_tier_level', 9200);
$tier->set('sbt_is_active', true);
$tier->save();
$tier->load();
$tier_group_id = $tier->get('sbt_grp_group_id');

// Combined tier teardown — registered before the order rows so it runs after
// them (LIFO), and after removing memberships so the group row can be deleted.
harness_defer(function () use ($tier, $tier_group_id, $subscriber) {
	$db = DbConnector::get_instance()->get_db_link();
	try {
		SubscriptionTier::removeUserFromAllTiers($subscriber->key);
		if ($tier_group_id) {
			$db->prepare('DELETE FROM grm_group_members WHERE grm_grp_group_id = ?')->execute(array($tier_group_id));
		}
		$db->prepare('DELETE FROM sbt_subscription_tiers WHERE sbt_subscription_tier_id = ?')->execute(array($tier->key));
		if ($tier_group_id) {
			$db->prepare('DELETE FROM grp_groups WHERE grp_group_id = ?')->execute(array($tier_group_id));
		}
	} catch (\Throwable $e) {
		echo "  WARNING: tier cleanup failed: " . $e->getMessage() . "\n";
	}
});

$product = new Product(NULL);
$product->set('pro_name', 'SW Test Plan ' . $run_id);
$product->set('pro_link', 'sw-test-plan-' . $run_id);
$product->set('pro_is_active', true);
$product->set('pro_sbt_subscription_tier_id', $tier->key);
$product->save();
$product->load();

$sub_id = 'sub_sw_' . $run_id;
$order_d = new Order(NULL);
$order_d->set('ord_usr_user_id', $subscriber->key);
$order_d->set('ord_total_cost', 15.00);
$order_d->set('ord_status', Order::STATUS_PAID);
$order_d->set('ord_stripe_session_id', 'cs_test_sw_' . $run_id . '_d');
$order_d->save();
$order_d->load();

$oi = new OrderItem(NULL);
$oi->set('odi_ord_order_id', $order_d->key);
$oi->set('odi_pro_product_id', $product->key);
$oi->set('odi_usr_user_id', $subscriber->key);
$oi->set('odi_stripe_subscription_id', $sub_id);
$oi->set('odi_subscription_status', 'active');
$oi->save();
$oi->load();

// Register FK-children last so LIFO deletes them first.
harness_register_row('pro_products', 'pro_product_id', $product->key);
harness_register_row('ord_orders', 'ord_order_id', $order_d->key);
harness_register_row('odi_order_items', 'odi_order_item_id', $oi->key);

$tier->addUser($subscriber->key, 'manual');
SubscriptionTier::clearUserCache($subscriber->key);
$granted = SubscriptionTier::GetUserTier($subscriber->key);
check($granted !== NULL && (int)$granted->key === (int)$tier->key, 'subscriber fixture holds the tier before cancellation (positive control)');

$payload_d = sw_event('evt_sw_' . $run_id . '_subdel', 'customer.subscription.deleted', array(
	'id'                  => $sub_id,
	'object'              => 'subscription',
	'status'              => 'canceled',
	'cancel_at_period_end' => false,
));
$r = sw_post($payload_d, sw_sign_header($payload_d, $endpoint_secret));
check($r['status'] === 200, 'customer.subscription.deleted accepted (200)', 'status ' . $r['status']);

$oi->load();
check($oi->get('odi_subscription_status') === 'canceled', 'the order item is marked canceled');
check($oi->get('odi_subscription_cancelled_time') !== null, 'cancellation timestamp is recorded on the order item');

SubscriptionTier::clearUserCache($subscriber->key);
check(SubscriptionTier::GetUserTier($subscriber->key) === null, 'subscription deletion strips the subscriber tier (entitlement revoked)');

harness_finish();
?>
