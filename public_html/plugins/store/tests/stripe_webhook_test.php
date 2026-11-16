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
 *      — the exact shape Stripe posts. A paid session with its checkout record is
 *      completed into its order with its lines; an
 *      invalid-signature post is refused with 400 and writes nothing; a replayed
 *      event id is suppressed (no second order); a session the buyer's return
 *      already made an order for gets no second one; and customer.subscription.deleted
 *      cancels the order item and strips the subscriber's tier.
 *
 * The endpoint script exits() and defines a function, so it is built to run once
 * per request — the dispatch half is driven over HTTP (a real request populates
 * php://input), never by including the file.
 *
 * Run: php plugins/store/tests/stripe_webhook_test.php [base_url] [origin_ip]
 *
 * @version 1.2.0 - the first checkout has its record and is completed from it (no operator alert, B156);
 *   a deleted subscription ends every line on it, revoking the tier once (hosting_paid_launch B10)
 * @version 1.1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/http.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_http_boot(isset($argv) ? $argv : array());
harness_boot();

require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/product_versions_class.php'));
require_once(PathHelper::getIncludePath('data/webhook_logs_class.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/TierBilling.php'));
require_once(PathHelper::getComposerAutoloadPath()); // Stripe SDK for the signature seam

$run_id = substr(md5(uniqid('sw', true)), 0, 8);

/**
 * Remove fixtures stranded by an earlier crashed run, matched on this suite's
 * own generated names so nothing real can be caught. Order matters: versions and
 * products reference the tier, and the tier group holds memberships.
 */
function sw_preclean() {
	$db = DbConnector::get_instance()->get_db_link();
	$removed = 0;
	try {
		$db->exec("DELETE FROM prv_product_versions WHERE prv_pro_product_id IN
			(SELECT pro_product_id FROM pro_products WHERE pro_link ~ '^sw-(hi|lo)-plan-[0-9a-f]{8}$')");
		$removed += $db->exec("DELETE FROM pro_products WHERE pro_link ~ '^sw-(hi|lo)-plan-[0-9a-f]{8}$'");
		$db->exec("DELETE FROM grm_group_members WHERE grm_grp_group_id IN
			(SELECT sbt_grp_group_id FROM sbt_subscription_tiers WHERE sbt_name ~ '^sw_tier_(hi|lo)_[0-9a-f]{8}$')");
		$db->exec("DELETE FROM grp_groups WHERE grp_group_id IN
			(SELECT sbt_grp_group_id FROM sbt_subscription_tiers WHERE sbt_name ~ '^sw_tier_(hi|lo)_[0-9a-f]{8}$')");
		$removed += $db->exec("DELETE FROM sbt_subscription_tiers WHERE sbt_name ~ '^sw_tier_(hi|lo)_[0-9a-f]{8}$'");
	} catch (\Throwable $e) {
		echo "  WARNING: preclean failed: " . $e->getMessage() . "\n";
	}
	return $removed;
}

$precleaned = sw_preclean();
if ($precleaned > 0) echo "  Precleaned $precleaned fixture row(s) stranded by an earlier run\n";

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

// --- a paid session the buyer never came back from is completed -----------
// The checkout's record of what was paid for, as the buyer's Pay button
// leaves it (ShoppingCart::record_payment()): the webhook makes the order from
// it, lines and all, with nothing for the operator to do (B156).

$admin = make_user('SwAdmin' . $run_id, 10);
$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = (int)$admin->key;
$_SESSION['permission'] = 10;
$_SESSION['test_mode'] = true;
$_SESSION['send_emails'] = false;
harness_set_setting_mem('checkout_type', 'none');
harness_set_setting_mem('products_active', '1');
$made = harness_call_logic_ok('plugins/store/admin/logic/admin_product_edit_logic.php', 'admin_product_edit_logic', array(
	'action' => 'add', 'json_confirm' => '1', 'pro_name' => 'SW Widget ' . $run_id,
	'pro_description' => 'stripe_webhook fixture', 'pro_short_description' => 'fixture',
	'pro_is_active' => '1', 'pro_max_purchase_count' => '0', 'pro_max_cart_count' => '0', 'pro_expires' => '0',
	'pro_link' => 'sw-widget-' . $run_id, 'system_requirements' => array('FullNameRequirement', 'EmailRequirement'),
));
preg_match('/pro_product_id=(\d+)/', (string)$made->redirect, $m);
$widget_id = (int)($m[1] ?? 0);
harness_defer(function () use ($widget_id) {
	try { $p = new Product($widget_id, TRUE); if ($p->key) $p->permanent_delete(); } catch (\Throwable $e) {}
});
harness_call_logic_ok('plugins/store/admin/logic/admin_product_edit_logic.php', 'admin_product_edit_logic', array(
	'action' => 'new_version', 'p' => $widget_id, 'version_name' => 'V', 'version_price' => '49.99',
	'prv_price_type' => 'single', 'prv_trial_period_days' => '0',
), 'POST');
$widget = new Product($widget_id, TRUE);

$sess_a = 'cs_test_sw_' . $run_id . '_a';
harness_defer(function () use ($sess_a) {
	DbConnector::get_instance()->get_db_link()->prepare('DELETE FROM gpr_gateway_payment_records WHERE gpr_ref = ?')->execute(array($sess_a));
});
$_SESSION['usr_user_id'] = (int)$subscriber->key;
$_SESSION['permission'] = 0;
GatewayPaymentRecord::$session_id_stand_in = 'swsess' . $run_id;
$cart = ShoppingCart::current();
$cart->clear_cart();
$cart->add_item($widget, array('product_version' => $widget->get_product_versions(TRUE)->get(0)->key,
	'full_name_first' => 'Sw', 'full_name_last' => 'Buyer', 'email' => $subscriber->get('usr_email')));
$cart->record_payment($sess_a, 'stripe_checkout');
$cart->clear_cart();
$_SESSION = array();
GatewayPaymentRecord::$session_id_stand_in = null;

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
	$lines = array();
	foreach (new MultiOrderItem(array('odi_ord_order_id' => $order->key)) as $line) {
		$lines[] = $line;
		harness_register_row('odi_order_items', 'odi_order_item_id', $line->key);
	}
	check((int)$order->get('ord_status') === Order::STATUS_PAID, 'order is marked STATUS_PAID');
	check((int)$order->get('ord_usr_user_id') === (int)$subscriber->key, 'order is linked to the client_reference_id user');
	check(count($lines) === 1 && (int)$lines[0]->get('odi_pro_product_id') === $widget_id,
		'the order is completed from the record, with its line', count($lines) . ' line(s)');
	check(trim((string)$order->get('ord_error')) === '', 'nothing for the operator: the order carries no error', (string)$order->get('ord_error'));
}
$gpr = GatewayPaymentRecord::GetByRef($sess_a);
check($gpr && $gpr->get('gpr_state') === GatewayPaymentRecord::STATE_COMPLETED, 'the checkout record is spent');

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

// --- a session the buyer's return already completed gets no second order ---

$sess_d = 'cs_test_sw_' . $run_id . '_d';
SystemBase::server_initiated_write(function () use ($sess_d, $subscriber) {
	$returned = new Order(NULL);
	$returned->set('ord_usr_user_id', $subscriber->key);
	$returned->set('ord_total_cost', 20);
	$returned->set('ord_stripe_session_id', $sess_d);
	$returned->set('ord_status', Order::STATUS_PAID);
	$returned->prepare();
	$returned->save();
});
foreach (sw_order_ids_for_session($sess_d) as $oid) {
	harness_register_row('ord_orders', 'ord_order_id', $oid);
}
$payload_d = sw_event('evt_sw_' . $run_id . '_after_return', 'checkout.session.completed', array(
	'id'                  => $sess_d,
	'object'              => 'checkout.session',
	'amount_total'        => 2000,
	'currency'            => 'usd',
	'client_reference_id' => (string)$subscriber->key,
	'payment_intent'      => 'pi_swd_' . $run_id,
	'subscription'        => null,
));
$r = sw_post($payload_d, sw_sign_header($payload_d, $endpoint_secret));
check($r['status'] === 200, 'checkout.session.completed after the return is accepted (200)', 'status ' . $r['status']);
$ids_d = sw_order_ids_for_session($sess_d);
check(count($ids_d) === 1, 'the session keeps its one order', 'orders: ' . count($ids_d));
if (count($ids_d) === 1) {
	$order_d = new Order($ids_d[0], TRUE);
	check($order_d->get('ord_stripe_payment_intent_id') === 'pi_swd_' . $run_id, 'the webhook records the payment intent on it');
}

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

// --- every line on the subscription ends; a replaced line is left ----------
// One Stripe Checkout session can put a tier line and a hosting line on one
// subscription, and a tier change leaves the line it replaced on the same id.

$plain = new Product(NULL);
$plain->set('pro_name', 'SW Hosting ' . $run_id);
$plain->set('pro_link', 'sw-hosting-' . $run_id);
$plain->set('pro_is_active', true);
$plain->save();
harness_register_row('pro_products', 'pro_product_id', $plain->key);
$sw_line = function ($product, $sub, $cancelled = null, $order = null) use ($order_d, $subscriber) {
	$line = new OrderItem(NULL);
	$line->set('odi_ord_order_id', ($order ?: $order_d)->key);
	$line->set('odi_pro_product_id', $product->key);
	$line->set('odi_usr_user_id', $subscriber->key);
	$line->set('odi_stripe_subscription_id', $sub);
	$line->set('odi_subscription_status', 'active');
	if ($cancelled) { $line->set('odi_subscription_cancelled_time', $cancelled); }
	$line->save();
	harness_register_row('odi_order_items', 'odi_order_item_id', $line->key);
	return $line;
};
$sub_mix = 'sub_swmix_' . $run_id;
// The tier line carries the period-end date (a cancel at period end marks one
// line), so its end is just past when the deletion arrives: it still ends.
$mix_tier = $sw_line($product, $sub_mix, gmdate('Y-m-d H:i:s', time() - 60));
$mix_host = $sw_line($plain, $sub_mix);
$tier->addUser($subscriber->key, 'manual');
SubscriptionTier::clearUserCache($subscriber->key);
$payload_mix = sw_event('evt_sw_' . $run_id . '_submix', 'customer.subscription.deleted', array(
	'id' => $sub_mix, 'object' => 'subscription', 'status' => 'canceled', 'cancel_at_period_end' => false));
$r = sw_post($payload_mix, sw_sign_header($payload_mix, $endpoint_secret));
check($r['status'] === 200, 'a subscription paying for two lines is deleted (200)', 'status ' . $r['status']);
$mix_tier->load();
$mix_host->load();
check($mix_tier->get('odi_subscription_status') === 'canceled' && $mix_host->get('odi_subscription_status') === 'canceled',
	'both lines on it end, the tier line and the hosting line');
SubscriptionTier::clearUserCache($subscriber->key);
check(SubscriptionTier::GetUserTier($subscriber->key) === null, 'and the tier is revoked, whichever line came first');

$sub_up = 'sub_swup_' . $run_id;
$replaced = $sw_line($product, $sub_up, gmdate('Y-m-d H:i:s', time() - 86400));
// A tier change makes its new line in an order of its own (change_tier_logic).
$up_order = new Order(NULL);
$up_order->set('ord_usr_user_id', $subscriber->key);
$up_order->set('ord_total_cost', 15.00);
$up_order->set('ord_status', Order::STATUS_PAID);
$up_order->save();
harness_register_row('ord_orders', 'ord_order_id', $up_order->key);
$current = $sw_line($product, $sub_up, null, $up_order);
$payload_up = sw_event('evt_sw_' . $run_id . '_subup', 'customer.subscription.deleted', array(
	'id' => $sub_up, 'object' => 'subscription', 'status' => 'canceled', 'cancel_at_period_end' => false));
$r = sw_post($payload_up, sw_sign_header($payload_up, $endpoint_secret));
$replaced->load();
$current->load();
check($r['status'] === 200 && $current->get('odi_subscription_status') === 'canceled',
	'after a tier change, the line now on the subscription ends');
check($replaced->get('odi_subscription_status') === 'active', 'the line it replaced is left as the change left it');

// A cart of a tier and hosting, then a tier change: the hosting line was not
// replaced by anything, so the deletion still ends it (reviewer2 B12 F2).
$sub_f2 = 'sub_swf2_' . $run_id;
$f2_old_tier = $sw_line($product, $sub_f2, gmdate('Y-m-d H:i:s', time() - 86400));
$f2_host = $sw_line($plain, $sub_f2);
$f2_order = new Order(NULL);
$f2_order->set('ord_usr_user_id', $subscriber->key);
$f2_order->set('ord_total_cost', 15.00);
$f2_order->set('ord_status', Order::STATUS_PAID);
$f2_order->save();
harness_register_row('ord_orders', 'ord_order_id', $f2_order->key);
$f2_new_tier = $sw_line($product, $sub_f2, null, $f2_order);
$payload_f2u = sw_event('evt_sw_' . $run_id . '_subf2u', 'customer.subscription.updated', array(
	'id' => $sub_f2, 'object' => 'subscription', 'status' => 'past_due', 'cancel_at_period_end' => false,
	'current_period_end' => time() + 86400, 'items' => array('object' => 'list', 'data' => array())));
$r = sw_post($payload_f2u, sw_sign_header($payload_f2u, $endpoint_secret));
$f2_host->load();
$f2_new_tier->load();
check($r['status'] === 200 && $f2_host->get('odi_subscription_status') === 'past_due' && $f2_new_tier->get('odi_subscription_status') === 'past_due',
	'after a tier change, a failed payment reaches the hosting line bought beside the tier as well as the new tier line');
$payload_f2 = sw_event('evt_sw_' . $run_id . '_subf2', 'customer.subscription.deleted', array(
	'id' => $sub_f2, 'object' => 'subscription', 'status' => 'canceled', 'cancel_at_period_end' => false));
$r = sw_post($payload_f2, sw_sign_header($payload_f2, $endpoint_secret));
$f2_old_tier->load();
$f2_host->load();
$f2_new_tier->load();
check($r['status'] === 200 && $f2_host->get('odi_subscription_status') === 'canceled' && $f2_new_tier->get('odi_subscription_status') === 'canceled'
	&& $f2_old_tier->get('odi_subscription_status') === 'active',
	'and the deletion ends both, leaving the replaced tier line as the change left it');

// --- a price change re-points the order item and the tier ------------------

// This is how a downgrade scheduled for end-of-period actually lands: Stripe
// swaps the price when the period rolls over and sends subscription.updated.
// Nothing else moves the subscriber onto the new tier, so if this handler stops
// re-deriving the product, a scheduled downgrade silently never takes effect
// and the customer keeps the higher tier for free.

/** A tier with its own product and priced version. Returns the parts by name. */
function sw_make_plan($run_id, $suffix, $level, $price_id, $price) {
	// Cleanup is registered immediately after each save, never at the end of the
	// helper: a throw partway through (a missing required field, say) would
	// otherwise strand everything created before it with nothing registered to
	// remove it.
	$tier = new SubscriptionTier(NULL);
	$tier->set('sbt_name', 'sw_tier_' . $suffix . '_' . $run_id);
	$tier->set('sbt_display_name', 'SW ' . strtoupper($suffix) . ' Tier');
	$tier->set('sbt_tier_level', $level);
	$tier->set('sbt_is_active', true);
	$tier->save();
	$tier->load();
	$group_id = $tier->get('sbt_grp_group_id');
	harness_defer(function () use ($tier, $group_id) {
		$db = DbConnector::get_instance()->get_db_link();
		try {
			if ($group_id) {
				$db->prepare('DELETE FROM grm_group_members WHERE grm_grp_group_id = ?')->execute(array($group_id));
			}
			$db->prepare('DELETE FROM sbt_subscription_tiers WHERE sbt_subscription_tier_id = ?')->execute(array($tier->key));
			if ($group_id) {
				$db->prepare('DELETE FROM grp_groups WHERE grp_group_id = ?')->execute(array($group_id));
			}
		} catch (\Throwable $e) {
			echo "  WARNING: plan cleanup failed: " . $e->getMessage() . "\n";
		}
	});

	$product = new Product(NULL);
	$product->set('pro_name', 'SW ' . strtoupper($suffix) . ' Plan ' . $run_id);
	$product->set('pro_link', 'sw-' . $suffix . '-plan-' . $run_id);
	$product->set('pro_is_active', true);
	$product->set('pro_sbt_subscription_tier_id', $tier->key);
	$product->save();
	$product->load();
	harness_register_row('pro_products', 'pro_product_id', $product->key);

	$version = new ProductVersion(NULL);
	$version->set('prv_pro_product_id', $product->key);
	$version->set('prv_version_name', 'Monthly');
	$version->set('prv_version_price', $price);
	$version->set('prv_status', 1);          // > 0 keeps it in the active-version filter
	$version->set('prv_price_type', 'month'); // recurring, matching a real tier plan
	$version->set('prv_stripe_price_id_test', $price_id);
	$version->save();
	$version->load();
	harness_register_row('prv_product_versions', 'prv_product_version_id', $version->key);

	return array('tier' => $tier, 'product' => $product, 'version' => $version);
}

$price_hi = 'price_sw_hi_' . $run_id;
$price_lo = 'price_sw_lo_' . $run_id;
$plan_hi = sw_make_plan($run_id, 'hi', 9310, $price_hi, 30.00);
$plan_lo = sw_make_plan($run_id, 'lo', 9300, $price_lo, 10.00);

$sub_pc = 'sub_swpc_' . $run_id;
$order_pc = new Order(NULL);
$order_pc->set('ord_usr_user_id', $subscriber->key);
$order_pc->set('ord_total_cost', 30.00);
$order_pc->set('ord_status', Order::STATUS_PAID);
$order_pc->set('ord_stripe_session_id', 'cs_test_sw_' . $run_id . '_pc');
$order_pc->save();
$order_pc->load();

$oi_pc = new OrderItem(NULL);
$oi_pc->set('odi_ord_order_id', $order_pc->key);
$oi_pc->set('odi_pro_product_id', $plan_hi['product']->key);
$oi_pc->set('odi_prv_product_version_id', $plan_hi['version']->key);
$oi_pc->set('odi_usr_user_id', $subscriber->key);
$oi_pc->set('odi_price', 30.00);
$oi_pc->set('odi_status', OrderItem::STATUS_PAID);
$oi_pc->set('odi_is_subscription', true);
$oi_pc->set('odi_stripe_subscription_id', $sub_pc);
$oi_pc->set('odi_subscription_status', 'active');
$oi_pc->save();
$oi_pc->load();

harness_register_row('ord_orders', 'ord_order_id', $order_pc->key);
harness_register_row('odi_order_items', 'odi_order_item_id', $oi_pc->key);

$plan_hi['tier']->addUser($subscriber->key, 'purchase', 'order', $order_pc->key);
SubscriptionTier::clearUserCache($subscriber->key);
$held = SubscriptionTier::GetUserTier($subscriber->key);
check($held !== NULL && (int)$held->key === (int)$plan_hi['tier']->key,
	'subscriber holds the higher tier before the price change (positive control)');

$payload_pc = sw_event('evt_sw_' . $run_id . '_pricechg', 'customer.subscription.updated', array(
	'id'                   => $sub_pc,
	'object'               => 'subscription',
	'status'               => 'active',
	'cancel_at_period_end' => false,
	'current_period_end'   => time() + 2592000,
	'items'                => array('data' => array(
		array('price' => array('id' => $price_lo)),
	)),
));
$r = sw_post($payload_pc, sw_sign_header($payload_pc, $endpoint_secret));
check($r['status'] === 200, 'price-change subscription.updated accepted (200)', 'status ' . $r['status']);

$oi_pc->load();
check((int)$oi_pc->get('odi_pro_product_id') === (int)$plan_lo['product']->key,
	'the order item is re-pointed at the newly billed product',
	'product ' . $oi_pc->get('odi_pro_product_id') . ', expected ' . $plan_lo['product']->key);
check((int)$oi_pc->get('odi_prv_product_version_id') === (int)$plan_lo['version']->key,
	'the order item is re-pointed at the newly billed version');

SubscriptionTier::clearUserCache($subscriber->key);
$now_held = SubscriptionTier::GetUserTier($subscriber->key);
check($now_held !== NULL && (int)$now_held->key === (int)$plan_lo['tier']->key,
	'the subscriber is moved to the tier matching the new price',
	'tier ' . ($now_held ? $now_held->key : 'none') . ', expected ' . $plan_lo['tier']->key);

harness_finish();
?>
